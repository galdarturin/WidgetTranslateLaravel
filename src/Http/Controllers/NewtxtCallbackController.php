<?php

namespace Newtxt\Laravel\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Newtxt\Laravel\Exceptions\CallbackValidationException;
use Newtxt\Laravel\NewtxtManager;
use Newtxt\Laravel\Security\CallbackSignatureVerifier;
use Throwable;

class NewtxtCallbackController extends Controller
{
    public function __construct(
        private readonly NewtxtManager $newtxt,
        private readonly CallbackSignatureVerifier $signatureVerifier,
    ) {
    }

    /**
     * Handle a signed NewTXT service callback.
     */
    public function __invoke(Request $request): JsonResponse
    {
        if (!(bool) config('newtxt.callback_enabled', false)) {
            return response()->json(['message' => 'Not found'], 404);
        }

        if (!$this->signatureVerifier->verify($request)) {
            return response()->json(['message' => 'Invalid callback signature'], 401);
        }

        $payload = $request->json()->all();
        if (!is_array($payload)) {
            return response()->json(['message' => 'Invalid callback payload'], 400);
        }

        $action = trim((string) ($payload['action'] ?? ''));
        if (!$this->actionAllowed($action)) {
            return response()->json(['message' => 'Callback action is not allowed'], 403);
        }

        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $requestId = trim((string) ($payload['requestId'] ?? ''));

        try {
            $result = $this->handleAction($action, $data);
        } catch (CallbackValidationException $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        } catch (Throwable) {
            return response()->json(['message' => 'Callback action failed'], 500);
        }

        return response()->json([
            'ok' => true,
            'requestId' => $requestId !== '' ? $requestId : null,
            'action' => $action,
            'result' => $result,
        ]);
    }

    /**
     * Execute one allow-listed callback action.
     */
    private function handleAction(string $action, array $data): array
    {
        return match ($action) {
            'health.check' => [
                'enabled' => $this->newtxt->enabled(),
                'timestamp' => gmdate('c'),
            ],
            'cache.clear' => $this->clearCache($data),
            'page.prewarm' => $this->prewarmPage($data),
            'translations.sync' => $this->syncTranslations($data),
            default => throw new CallbackValidationException('Callback action is not supported'),
        };
    }

    /**
     * Clear one local rendered page cache entry.
     */
    private function clearCache(array $data): array
    {
        [$languageCode, $path] = $this->languageAndPath($data);
        $this->newtxt->clearRenderedPageCache($languageCode, $path);

        return [
            'languageCode' => $languageCode,
            'path' => $path,
            'cleared' => true,
        ];
    }

    /**
     * Render and store one translated page in local cache/artifacts.
     */
    private function prewarmPage(array $data): array
    {
        [$languageCode, $path] = $this->languageAndPath($data);
        $rendered = $this->newtxt->rememberRenderedPage($languageCode, $path, $this->safeOptions($data));

        if (!is_array($rendered) || !$this->newtxt->isRenderedPageReady($rendered, $languageCode)) {
            throw new CallbackValidationException('Translated page is not ready');
        }

        return [
            'languageCode' => $languageCode,
            'path' => $path,
            'htmlHash' => $rendered['htmlHash'] ?? null,
            'pageHash' => $rendered['pageHash'] ?? null,
            'fromCache' => (bool) ($rendered['fromCache'] ?? false),
            'fromLocalSnapshot' => (bool) ($rendered['fromLocalSnapshot'] ?? false),
            'cacheSource' => $rendered['cacheSource'] ?? null,
            'storedAt' => $rendered['storedAt'] ?? null,
        ];
    }

    /**
     * Sync page node translations into the local hashed translation store.
     */
    private function syncTranslations(array $data): array
    {
        [$languageCode, $path] = $this->languageAndPath($data);
        $stored = $this->newtxt->syncHashedTranslations($languageCode, $path, $this->safeOptions($data));

        return [
            'languageCode' => $languageCode,
            'path' => $path,
            'stored' => $stored,
        ];
    }

    /**
     * Validate common language and path input.
     */
    private function languageAndPath(array $data): array
    {
        $languageCode = strtolower(trim((string) ($data['languageCode'] ?? '')));
        $path = '/' . ltrim(trim((string) ($data['path'] ?? '')), '/');

        if (preg_match('/^[a-z0-9_-]{2,20}$/', $languageCode) !== 1) {
            throw new CallbackValidationException('languageCode is invalid');
        }

        if ($path === '/' && trim((string) ($data['path'] ?? '')) === '') {
            throw new CallbackValidationException('path is required');
        }

        if (strlen($path) > 2048 || str_contains($path, '://') || str_starts_with($path, '//')) {
            throw new CallbackValidationException('path is invalid');
        }

        return [$languageCode, $path === '//' ? '/' : $path];
    }

    /**
     * Allow only supported render/sync options from callback payloads.
     */
    private function safeOptions(array $data): array
    {
        $options = [];
        $rawOptions = is_array($data['options'] ?? null) ? $data['options'] : [];

        foreach (['forceRefreshCache', 'autoTranslateIfMissing', 'allowPartialTranslations', 'allowPartialHtml'] as $key) {
            if (array_key_exists($key, $rawOptions)) {
                $options[$key] = filter_var($rawOptions[$key], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? false;
            }
        }

        if (isset($rawOptions['urlMode'])) {
            $urlMode = strtolower(trim((string) $rawOptions['urlMode']));
            if (preg_match('/^[a-z0-9_-]{1,40}$/', $urlMode) === 1) {
                $options['urlMode'] = $urlMode;
            }
        }

        return $options;
    }

    /**
     * Check whether the configured action allow-list contains the request.
     */
    private function actionAllowed(string $action): bool
    {
        $allowed = collect(config('newtxt.callback_allowed_actions', []))
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->all();

        return in_array($action, $allowed, true);
    }
}
