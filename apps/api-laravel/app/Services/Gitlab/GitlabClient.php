<?php

namespace App\Services\Gitlab;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

/**
 * Talks to the GitLab API on behalf of the stored connection.
 *
 * Two details are deliberate and worth keeping:
 *  - a custom CA extends the system bundle instead of replacing it, so an
 *    internal certificate never breaks access to public hosts;
 *  - the clone check runs `git ls-remote` because a scope listing can look
 *    correct while cloning still fails — only the real clone path proves it.
 */
class GitlabClient
{
    const REQUIRED_SCOPES = ['read_api', 'read_repository'];

    const API_PREFIX = '/api/v4';

    /** Gitea's API lives under a different prefix, with a different auth header. */
    const GITEA_API_PREFIX = '/api/v1';

    /**
     * Gitea addresses a repository by owner/name, and this client is asked for it
     * by the id the dashboard holds. The listing that produced those ids is cached
     * briefly so a branch, tree or file call does not re-list every time.
     */
    const GITEA_REPO_MAP_TTL_SECONDS = 300;

    /** Gitea caps a listing at 50 entries per page. */
    const GITEA_PAGE_SIZE = 50;

    const PAGE_SIZE = 100;

    /** Bounds work and payload on very large instances; `truncated` says so. */
    const MAX_PAGES = 3;

    const MAX_PINNED_LOOKUPS = 20;

    const TIMEOUT_SECONDS = 15;

    const CLONE_TIMEOUT_SECONDS = 25;

    private const SYSTEM_CA_BUNDLE = '/etc/ssl/certs/ca-certificates.crt';

    /** @var array<string, string> merged CA bundles, keyed by the custom certificate */
    private static array $caFiles = [];

    public static function normalizeBaseUrl(string $input): string
    {
        $trimmed = rtrim(trim($input), '/');

        if ($trimmed === '') {
            return '';
        }

        return preg_match('#^https?://#i', $trimmed) === 1 ? $trimmed : 'https://'.$trimmed;
    }

    /** Strips a token out of anything about to be logged, stored or returned. */
    public static function redact(string $text, string $token): string
    {
        return $token === '' ? $text : str_replace($token, '[redacted]', $text);
    }

    /**
     * The URL a clone uses: the token rides in it, so git needs no credential helper
     * and nothing is written to the host's home.
     */
    public static function cloneUrl(GitlabConnectionData $connection, string $projectPath): string
    {
        $parsed = parse_url($connection->baseUrl);
        $basePath = rtrim($parsed['path'] ?? '', '/');
        $path = ltrim((string) preg_replace('/\.git$/', '', $projectPath), '/');

        return sprintf(
            '%s://oauth2:%s@%s%s/%s.git',
            $parsed['scheme'] ?? 'https',
            rawurlencode($connection->token),
            $parsed['host'] ?? '',
            $basePath,
            $path
        );
    }

    public function listProjects(GitlabConnectionData $connection, ?string $search = null): array
    {
        if ($connection->isGitea()) {
            return $this->listGiteaProjects($connection, $search);
        }

        $projects = [];
        $page = '1';
        $pagesFetched = 0;

        while ($page !== '' && $pagesFetched < self::MAX_PAGES) {
            $query = [
                'per_page' => self::PAGE_SIZE,
                'page' => $page,
                'order_by' => 'last_activity_at',
                'sort' => 'desc',
                'membership' => 'true',
            ];

            $needle = $search === null ? '' : trim($search);
            if ($needle !== '') {
                $query['search'] = $needle;
            }

            $response = $this->request($connection, 'GET', '/projects?'.http_build_query($query));

            if (! $response['ok']) {
                throw new GitlabApiError(
                    $this->describeHttpError($connection, $response['raw'], $response['status']),
                    $response['status']
                );
            }

            if (is_array($response['data'])) {
                array_push($projects, ...$response['data']);
            }

            $page = (string) ($response['headers']['x-next-page'][0] ?? '');
            $pagesFetched++;
        }

        return ['projects' => $projects, 'truncated' => $page !== ''];
    }

    public function listBranches(GitlabConnectionData $connection, int $projectId): array
    {
        if ($connection->isGitea()) {
            return $this->listGiteaBranches($connection, $projectId);
        }

        $branches = [];
        $page = '1';
        $pagesFetched = 0;

        while ($page !== '' && $pagesFetched < self::MAX_PAGES) {
            $response = $this->request(
                $connection,
                'GET',
                "/projects/{$projectId}/repository/branches?".http_build_query([
                    'per_page' => self::PAGE_SIZE,
                    'page' => $page,
                ])
            );

            if (! $response['ok']) {
                throw new GitlabApiError(
                    $this->describeHttpError($connection, $response['raw'], $response['status']),
                    $response['status']
                );
            }

            if (is_array($response['data'])) {
                array_push($branches, ...$response['data']);
            }

            $page = (string) ($response['headers']['x-next-page'][0] ?? '');
            $pagesFetched++;
        }

        return ['branches' => $branches, 'truncated' => $page !== ''];
    }

    /**
     * Looks up projects by id so a pinned project still appears even when it
     * falls outside the recency window the listing fetched.
     */
    public function fetchProjectsByIds(GitlabConnectionData $connection, array $ids): array
    {
        if ($connection->isGitea()) {
            return $this->giteaProjectsByIds($connection, $ids);
        }

        $found = [];

        foreach (array_slice($ids, 0, self::MAX_PINNED_LOOKUPS) as $id) {
            try {
                $response = $this->request($connection, 'GET', "/projects/{$id}");
                if ($response['ok'] && is_array($response['data'])) {
                    $found[] = $response['data'];
                }
            } catch (ConnectionException) {
                // A lookup that fails simply does not contribute a project.
            }
        }

        return $found;
    }

    /** Reads a file's raw contents at a ref; null when the file is absent. */
    public function getFileRaw(GitlabConnectionData $connection, int $projectId, string $filePath, string $ref): ?string
    {
        if ($connection->isGitea()) {
            return $this->giteaFileRaw($connection, $projectId, $filePath, $ref);
        }

        $path = rawurlencode($filePath);
        $query = http_build_query(['ref' => $ref]);
        $response = $this->request($connection, 'GET', "/projects/{$projectId}/repository/files/{$path}/raw?{$query}");

        if ($response['status'] === 404) {
            return null;
        }

        if (! $response['ok']) {
            throw new GitlabApiError(
                $this->describeHttpError($connection, $response['raw'], $response['status']),
                $response['status']
            );
        }

        return $response['raw'];
    }

    /**
     * Lists one directory level at a ref. Null means the directory does not
     * exist, which is different from an empty one.
     */
    public function getTree(GitlabConnectionData $connection, int $projectId, string $ref, string $path = ''): ?array
    {
        if ($connection->isGitea()) {
            return $this->giteaTree($connection, $projectId, $ref, $path);
        }

        $entries = [];
        $page = '1';
        $pagesFetched = 0;

        while ($page !== '' && $pagesFetched < self::MAX_PAGES) {
            $query = ['ref' => $ref, 'per_page' => self::PAGE_SIZE, 'page' => $page];
            if ($path !== '') {
                $query['path'] = $path;
            }

            $response = $this->request(
                $connection,
                'GET',
                "/projects/{$projectId}/repository/tree?".http_build_query($query)
            );

            if ($response['status'] === 404) {
                return null;
            }

            if (! $response['ok']) {
                throw new GitlabApiError(
                    $this->describeHttpError($connection, $response['raw'], $response['status']),
                    $response['status']
                );
            }

            if (is_array($response['data'])) {
                array_push($entries, ...$response['data']);
            }

            $page = (string) ($response['headers']['x-next-page'][0] ?? '');
            $pagesFetched++;
        }

        return $entries;
    }

    public function verifyConnection(GitlabConnectionData $connection, ?string $testRepo = null): array
    {
        if ($connection->isGitea()) {
            return $this->verifyGiteaConnection($connection, $testRepo);
        }

        $result = [
            'ok' => false,
            'baseUrl' => $connection->baseUrl,
            'identity' => null,
            'tokenName' => null,
            'scopes' => null,
            'scopesReadable' => false,
            'expiresAt' => null,
            'daysUntilExpiry' => null,
            'missingScopes' => [],
            'warnings' => [],
            'clone' => ['attempted' => false, 'ok' => false, 'repo' => null, 'message' => null],
            'error' => null,
        ];

        try {
            $self = $this->request($connection, 'GET', '/personal_access_tokens/self');
        } catch (ConnectionException $exception) {
            $result['error'] = self::redact($exception->getMessage(), $connection->token);

            return $result;
        }

        if ($self['status'] === 401) {
            $result['error'] = $this->describeHttpError($connection, $self['raw'], $self['status']);
        } elseif ($self['ok'] && is_array($self['data'])) {
            $result['scopesReadable'] = true;
            $result['scopes'] = is_array($self['data']['scopes'] ?? null) ? $self['data']['scopes'] : [];
            $result['tokenName'] = $self['data']['name'] ?? null;
            $result['expiresAt'] = $self['data']['expires_at'] ?? null;

            if ($result['expiresAt'] !== null) {
                $days = (int) floor((strtotime((string) $result['expiresAt']) - time()) / 86400);
                $result['daysUntilExpiry'] = $days;

                if ($days < 0) {
                    $result['warnings'][] = 'This token has already expired.';
                } elseif ($days <= 14) {
                    $result['warnings'][] = "This token expires in {$days} day(s).";
                }
            }

            $result['missingScopes'] = array_values(array_diff(self::REQUIRED_SCOPES, $result['scopes']));
        } else {
            // Older GitLab versions and group/project tokens cannot introspect themselves.
            $result['warnings'][] = "Could not read this token's scopes. Group and project access tokens, "
                .'and older GitLab versions, do not support /personal_access_tokens/self — verify scopes '
                .'manually as read_api + read_repository.';
        }

        try {
            $user = $this->request($connection, 'GET', '/user');
            if ($user['ok'] && is_array($user['data'])) {
                $result['identity'] = $user['data'];
            } elseif ($user['status'] === 403) {
                $result['warnings'][] = "Could not read the token's user identity — this needs read_api or read_user.";
            }
        } catch (ConnectionException) {
            $result['warnings'][] = 'Could not reach /user to confirm the token identity.';
        }

        if ($testRepo !== null && trim($testRepo) !== '') {
            $result['clone'] = $this->verifyClone($connection, $testRepo);

            // Public projects clone without credentials, so a green clone check
            // alone must not be read as confirmation that the token is valid.
            if ($result['clone']['ok'] && $result['identity'] === null) {
                $result['warnings'][] = 'The repository was reachable, but public projects clone anonymously — '
                    .'this does not confirm the token works. It only proves a private repository would be '
                    .'reachable with a valid token.';
            }
        }

        $result['ok'] = $result['error'] === null
            && $result['identity'] !== null
            && $result['missingScopes'] === []
            && (! $result['clone']['attempted'] || $result['clone']['ok']);

        return $result;
    }

    /**
     * Gitea proves a token by answering who it belongs to; it has no equivalent of
     * GitLab's token introspection, so scopes are taken on trust and said so.
     */
    private function verifyGiteaConnection(GitlabConnectionData $connection, ?string $testRepo): array
    {
        $result = [
            'ok' => false,
            'baseUrl' => $connection->baseUrl,
            'identity' => null,
            'tokenName' => null,
            'scopes' => null,
            'scopesReadable' => false,
            'expiresAt' => null,
            'daysUntilExpiry' => null,
            'missingScopes' => [],
            'warnings' => [],
            'clone' => ['attempted' => false, 'ok' => false, 'repo' => null, 'message' => null],
            'error' => null,
        ];

        try {
            $user = $this->request($connection, 'GET', '/user');
        } catch (ConnectionException $exception) {
            $result['error'] = self::redact($exception->getMessage(), $connection->token);

            return $result;
        }

        if ($user['status'] === 401) {
            $result['error'] = $this->describeHttpError($connection, $user['raw'], $user['status']);
        } elseif ($user['ok'] && is_array($user['data'])) {
            $result['identity'] = $user['data'];
            $result['tokenName'] = $user['data']['login'] ?? null;
            $result['warnings'][] = 'Gitea does not report what a token may do — make sure it can read '
                .'repositories (a token created with the "repo" scope is enough).';
        } else {
            $result['error'] = $this->describeHttpError($connection, $user['raw'], $user['status']);
        }

        if ($testRepo !== null && trim($testRepo) !== '') {
            $result['clone'] = $this->verifyClone($connection, $testRepo);
        }

        $result['ok'] = $result['error'] === null
            && $result['identity'] !== null
            && $result['missingScopes'] === []
            && (! $result['clone']['attempted'] || $result['clone']['ok']);

        return $result;
    }

    private function verifyClone(GitlabConnectionData $connection, string $repoPath): array
    {
        $cleanRepo = ltrim(trim($repoPath), '/');
        $cleanRepo = preg_replace('/\.git$/', '', $cleanRepo) ?? $cleanRepo;

        if ($cleanRepo === '') {
            return ['attempted' => false, 'ok' => false, 'repo' => null, 'message' => null];
        }

        $parsed = parse_url($connection->baseUrl);
        $basePath = rtrim($parsed['path'] ?? '', '/');
        $url = sprintf(
            '%s://oauth2:%s@%s%s/%s.git',
            $parsed['scheme'] ?? 'https',
            rawurlencode($connection->token),
            $parsed['host'] ?? '',
            $basePath,
            $cleanRepo
        );

        $args = [];
        if ($connection->caCertificate) {
            $args[] = '-c';
            $args[] = 'http.sslCAInfo='.$this->caBundleFor($connection->caCertificate);
        }
        array_push($args, 'ls-remote', '--heads', $url);

        $process = new Process(
            array_merge(['git'], $args),
            null,
            // Never let git block waiting for credentials on a headless server.
            ['GIT_TERMINAL_PROMPT' => '0', 'GIT_ASKPASS' => ''],
            null,
            self::CLONE_TIMEOUT_SECONDS
        );

        try {
            $process->run();
        } catch (\Throwable $exception) {
            return [
                'attempted' => true,
                'ok' => false,
                'repo' => $cleanRepo,
                'message' => self::redact($exception->getMessage(), $connection->token),
            ];
        }

        if ($process->isSuccessful()) {
            $branches = count(array_filter(explode("\n", $process->getOutput())));
            $plural = $branches === 1 ? '' : 'es';

            return [
                'attempted' => true,
                'ok' => true,
                'repo' => $cleanRepo,
                'message' => "Cloned metadata successfully — {$branches} branch{$plural} visible.",
            ];
        }

        $detail = trim($process->getErrorOutput()) ?: $process->getOutput();

        return [
            'attempted' => true,
            'ok' => false,
            'repo' => $cleanRepo,
            'message' => self::redact(mb_substr($detail, 0, 800), $connection->token),
        ];
    }

    /**
     * A custom CA extends the system bundle rather than replacing it, matching
     * the behaviour the previous implementation relied on.
     */
    private function caBundleFor(string $caCertificate): string
    {
        $key = hash('sha256', $caCertificate);

        if (isset(self::$caFiles[$key]) && is_file(self::$caFiles[$key])) {
            return self::$caFiles[$key];
        }

        $system = is_file(self::SYSTEM_CA_BUNDLE) ? (string) file_get_contents(self::SYSTEM_CA_BUNDLE) : '';
        $path = tempnam(sys_get_temp_dir(), 'ft-ca-');
        file_put_contents($path, $system."\n".$caCertificate);

        return self::$caFiles[$key] = $path;
    }

    // ----------------------------------------------------------------------
    // Gitea
    //
    // The dashboard, the pipeline and the inspector all speak in GitLab's shapes
    // (a numeric project id, `path_with_namespace`, a tree of tree/blob entries).
    // Everything below exists to keep that true on Gitea, so nothing outside this
    // client has to know which forge it is talking to.
    // ----------------------------------------------------------------------

    private function listGiteaProjects(GitlabConnectionData $connection, ?string $search): array
    {
        $projects = [];
        $page = '1';
        $pagesFetched = 0;
        $needle = $search === null ? '' : strtolower(trim($search));

        while ($page !== '' && $pagesFetched < self::MAX_PAGES) {
            $response = $this->request($connection, 'GET', '/user/repos?'.http_build_query([
                'limit' => self::GITEA_PAGE_SIZE,
                'page' => $page,
                'sort' => 'updated',
            ]));

            if (! $response['ok']) {
                throw new GitlabApiError(
                    $this->describeHttpError($connection, $response['raw'], $response['status']),
                    $response['status']
                );
            }

            foreach (is_array($response['data']) ? $response['data'] : [] as $repo) {
                if (! is_array($repo)) {
                    continue;
                }

                $project = $this->giteaProject($repo);

                // Gitea's own search covers public repositories only, so the
                // filter is applied here over what the token can actually see.
                if ($needle === '' || str_contains(strtolower($project['path_with_namespace']), $needle)) {
                    $projects[] = $project;
                }
            }

            $page = $this->nextPage($response['headers']);
            $pagesFetched++;
        }

        return ['projects' => $projects, 'truncated' => $page !== ''];
    }

    /** A Gitea repository, in the shape the rest of the application reads. */
    private function giteaProject(array $repo): array
    {
        return [
            'id' => (int) ($repo['id'] ?? 0),
            'name' => (string) ($repo['name'] ?? ''),
            'path_with_namespace' => (string) ($repo['full_name'] ?? ''),
            'default_branch' => $repo['default_branch'] ?? null,
            'visibility' => ! empty($repo['private'])
                ? 'private'
                : (! empty($repo['internal']) ? 'internal' : 'public'),
            'last_activity_at' => $repo['updated_at'] ?? ($repo['created_at'] ?? null),
            'web_url' => (string) ($repo['html_url'] ?? ''),
        ];
    }

    private function listGiteaBranches(GitlabConnectionData $connection, int $projectId): array
    {
        $repository = $this->giteaRepository($connection, $projectId);
        $branches = [];
        $page = '1';
        $pagesFetched = 0;

        while ($page !== '' && $pagesFetched < self::MAX_PAGES) {
            $response = $this->request(
                $connection,
                'GET',
                '/repos/'.$this->encodePath($repository['full_name']).'/branches?'.http_build_query([
                    'limit' => self::GITEA_PAGE_SIZE,
                    'page' => $page,
                ])
            );

            if (! $response['ok']) {
                throw new GitlabApiError(
                    $this->describeHttpError($connection, $response['raw'], $response['status']),
                    $response['status']
                );
            }

            foreach (is_array($response['data']) ? $response['data'] : [] as $branch) {
                if (! is_array($branch)) {
                    continue;
                }

                $name = (string) ($branch['name'] ?? '');

                $branches[] = [
                    'name' => $name,
                    // Gitea does not flag the default branch per entry.
                    'default' => $name !== '' && $name === $repository['default_branch'],
                    'protected' => (bool) ($branch['protected'] ?? false),
                    'commit' => [
                        'created_at' => $branch['commit']['timestamp'] ?? ($branch['commit']['created'] ?? null),
                    ],
                ];
            }

            $page = $this->nextPage($response['headers']);
            $pagesFetched++;
        }

        return ['branches' => $branches, 'truncated' => $page !== ''];
    }

    private function giteaProjectsByIds(GitlabConnectionData $connection, array $ids): array
    {
        $found = [];

        foreach (array_slice($ids, 0, self::MAX_PINNED_LOOKUPS) as $id) {
            $repository = $this->giteaRepository($connection, (int) $id, false);

            if ($repository !== null) {
                $found[] = $this->giteaProject($repository['raw']);
            }
        }

        return $found;
    }

    private function giteaTree(GitlabConnectionData $connection, int $projectId, string $ref, string $path): ?array
    {
        $repository = $this->giteaRepository($connection, $projectId);
        $trimmed = trim($path, '/');
        $suffix = $trimmed === '' ? '' : '/'.$this->encodePath($trimmed);

        $response = $this->request(
            $connection,
            'GET',
            '/repos/'.$this->encodePath($repository['full_name']).'/contents'.$suffix
                .'?'.http_build_query(['ref' => $ref])
        );

        if ($response['status'] === 404) {
            return null;
        }

        if (! $response['ok']) {
            throw new GitlabApiError(
                $this->describeHttpError($connection, $response['raw'], $response['status']),
                $response['status']
            );
        }

        // A file answers with a single object; only directories are walked here.
        if (! is_array($response['data']) || isset($response['data']['name'])) {
            return [];
        }

        $entries = [];

        foreach ($response['data'] as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $entries[] = [
                'name' => (string) ($entry['name'] ?? ''),
                'type' => ($entry['type'] ?? '') === 'dir' ? 'tree' : 'blob',
                'path' => (string) ($entry['path'] ?? ''),
            ];
        }

        return $entries;
    }

    private function giteaFileRaw(GitlabConnectionData $connection, int $projectId, string $filePath, string $ref): ?string
    {
        $repository = $this->giteaRepository($connection, $projectId);

        $response = $this->request(
            $connection,
            'GET',
            '/repos/'.$this->encodePath($repository['full_name']).'/raw/'.$this->encodePath($filePath)
                .'?'.http_build_query(['ref' => $ref])
        );

        if ($response['status'] === 404) {
            return null;
        }

        if (! $response['ok']) {
            throw new GitlabApiError(
                $this->describeHttpError($connection, $response['raw'], $response['status']),
                $response['status']
            );
        }

        return $response['raw'];
    }

    /**
     * The id the dashboard holds, turned into the owner/name Gitea addresses it by.
     *
     * @return array{id: int, full_name: string, default_branch: string, raw: array}|null
     */
    private function giteaRepository(GitlabConnectionData $connection, int $projectId, bool $required = true): ?array
    {
        $entry = $this->giteaRepoMap($connection)[$projectId] ?? null;

        if ($entry === null) {
            if ($required) {
                throw new GitlabApiError("No repository with id {$projectId} is visible to this token.", 404);
            }

            return null;
        }

        return $entry;
    }

    /**
     * The token's repositories, cached briefly: Gitea has no endpoint that takes
     * an id, so an id is resolved through the listing that produced it.
     *
     * @return array<int, array{id: int, full_name: string, default_branch: string, raw: array}>
     */
    private function giteaRepoMap(GitlabConnectionData $connection): array
    {
        $key = 'gitea-repositories:'.sha1($connection->baseUrl.'|'.$connection->token);

        return cache()->remember($key, self::GITEA_REPO_MAP_TTL_SECONDS, function () use ($connection) {
            $map = [];

            foreach ($this->listGiteaProjects($connection, null)['projects'] as $project) {
                $id = (int) $project['id'];

                $map[$id] = [
                    'id' => $id,
                    'full_name' => (string) $project['path_with_namespace'],
                    'default_branch' => (string) ($project['default_branch'] ?? ''),
                    'raw' => [
                        'id' => $id,
                        'name' => (string) $project['name'],
                        'full_name' => (string) $project['path_with_namespace'],
                        'default_branch' => $project['default_branch'] ?? null,
                        'private' => ($project['visibility'] ?? '') === 'private',
                        'internal' => ($project['visibility'] ?? '') === 'internal',
                        'updated_at' => $project['last_activity_at'] ?? null,
                        'html_url' => (string) $project['web_url'],
                    ],
                ];
            }

            return $map;
        });
    }

    /** The next page number from a `Link: <…page=N…>; rel="next"` header. */
    private function nextPage(array $headers): string
    {
        $link = (string) ($headers['link'][0] ?? '');

        if ($link !== '' && preg_match('/[?&]page=(\d+)[^>]*>;\s*rel="next"/i', $link, $matches) === 1) {
            return $matches[1];
        }

        return '';
    }

    /** Encodes a path for a URL without encoding its separators. */
    private function encodePath(string $value): string
    {
        return implode('/', array_map('rawurlencode', explode('/', trim($value, '/'))));
    }

    private function request(GitlabConnectionData $connection, string $method, string $path): array
    {
        $headers = $connection->isGitea()
            ? ['Authorization' => 'token '.$connection->token, 'accept' => 'application/json']
            : ['PRIVATE-TOKEN' => $connection->token, 'accept' => 'application/json'];

        $pending = Http::withHeaders($headers)->timeout(self::TIMEOUT_SECONDS)
            ->withOptions(['verify' => $connection->caCertificate ? $this->caBundleFor($connection->caCertificate) : true]);

        $prefix = $connection->isGitea() ? self::GITEA_API_PREFIX : self::API_PREFIX;
        $url = $connection->baseUrl.$prefix.$path;

        $response = $method === 'POST' ? $pending->post($url) : $pending->get($url);

        $data = null;
        try {
            $data = $response->json();
        } catch (\Throwable) {
            $data = null;
        }

        return [
            'status' => $response->status(),
            'ok' => $response->successful(),
            'raw' => $response->body(),
            'data' => $data,
            'headers' => $response->headers(),
        ];
    }

    private function describeHttpError(GitlabConnectionData $connection, string $raw, int $status): string
    {
        $safe = self::redact(mb_substr($raw, 0, 500), $connection->token);
        $forge = $connection->isGitea() ? 'Gitea' : 'GitLab';

        return match ($status) {
            401 => 'Authentication failed (401). The token is invalid, revoked, or expired.',
            403 => "Permission denied (403). The token is probably missing a required scope — "
                ."listing repositories needs read_api on GitLab and the repo scope on Gitea. {$safe}",
            404 => 'Not found (404). Check the base URL and that the API is reachable.',
            default => "{$forge} responded with {$status}. {$safe}",
        };
    }
}
