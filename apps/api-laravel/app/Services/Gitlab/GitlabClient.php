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

    public function listProjects(GitlabConnectionData $connection, ?string $search = null): array
    {
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

    private function request(GitlabConnectionData $connection, string $method, string $path): array
    {
        $pending = Http::withHeaders([
            'PRIVATE-TOKEN' => $connection->token,
            'accept' => 'application/json',
        ])->timeout(self::TIMEOUT_SECONDS)
            ->withOptions(['verify' => $connection->caCertificate ? $this->caBundleFor($connection->caCertificate) : true]);

        $url = $connection->baseUrl.self::API_PREFIX.$path;

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

        return match ($status) {
            401 => 'Authentication failed (401). The token is invalid, revoked, or expired.',
            403 => 'Permission denied (403). The token is probably missing a required scope — '
                ."listing projects needs read_api. {$safe}",
            404 => 'Not found (404). Check the base URL and that the API is reachable.',
            default => "GitLab responded with {$status}. {$safe}",
        };
    }
}
