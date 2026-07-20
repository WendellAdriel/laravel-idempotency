<?php

declare(strict_types=1);

namespace WendellAdriel\Idempotency\Support;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class RequestFingerprint
{
    public function storageKey(Request $request, string $scopePrefix, string $header, string $clientKey): string
    {
        return hash('xxh128', implode('|', [
            $this->routeIdentity($request),
            strtoupper($request->method()),
            $scopePrefix,
            $header,
            $clientKey,
        ]));
    }

    public function fingerprint(Request $request): string
    {
        return hash('xxh128', implode('|', [
            strtoupper($request->method()),
            $this->routeIdentity($request),
            $request->getQueryString() ?? '',
            $this->hashPayload($request),
            $request->getContentTypeFormat() ?? '',
        ]));
    }

    public function routeIdentity(Request $request): string
    {
        /** @var Route|null $route */
        $route = $request->route();

        return match (true) {
            $route === null => $request->getPathInfo(),
            is_string($route->getName()) => $route->getName(),
            default => ($route->getDomain() ?? '') . '/' . $route->uri(),
        };
    }

    private function hashPayload(Request $request): string
    {
        if ($request->isJson()) {
            $decoded = json_decode($request->getContent(), true);

            if (is_array($decoded)) {
                $this->recursiveKeySort($decoded);

                return hash('xxh128', (string) json_encode($decoded));
            }
        }

        if ($request->request->count() > 0 || $request->files->count() > 0) {
            return $this->hashFormPayload($request);
        }

        return hash('xxh128', $request->getContent());
    }

    private function hashFormPayload(Request $request): string
    {
        $fields = $request->request->all();
        $this->recursiveKeySort($fields);

        return hash('xxh128', (string) json_encode([
            'fields' => $fields,
            'files' => $this->describeFiles($request->files->all()),
        ]));
    }

    /**
     * @param  array<int|string, mixed>  $files
     * @return array<int|string, mixed>
     */
    private function describeFiles(array $files): array
    {
        $described = [];

        foreach ($files as $key => $file) {
            $described[$key] = match (true) {
                $file instanceof UploadedFile => $this->describeFile($file),
                is_array($file) => $this->describeFiles($file),
                default => null,
            };
        }

        ksort($described);

        return $described;
    }

    /**
     * @return array{name: string, size: int|null, hash: string}
     */
    private function describeFile(UploadedFile $file): array
    {
        if (! $file->isValid()) {
            return [
                'name' => $file->getClientOriginalName(),
                'size' => null,
                'hash' => 'invalid:' . $file->getError(),
            ];
        }

        $hash = hash_file('xxh128', $file->getPathname());

        return [
            'name' => $file->getClientOriginalName(),
            'size' => $file->getSize(),
            'hash' => $hash !== false ? $hash : 'unreadable',
        ];
    }

    /**
     * @param  array<int|string, mixed>  $array
     */
    private function recursiveKeySort(array &$array): void
    {
        ksort($array);

        foreach ($array as &$value) {
            if (is_array($value)) {
                $this->recursiveKeySort($value);
            }
        }
    }
}
