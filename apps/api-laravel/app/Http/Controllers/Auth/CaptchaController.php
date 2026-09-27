<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Auth\IssueCaptchaAction;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CaptchaController extends Controller
{
    public function show(Request $request): JsonResponse
    {
        return $this->legacyResponse(run(new IssueCaptchaAction($request->ip())));
    }
}
