<?php

namespace App;

use App\Models\Payment;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PaymentReceiptStorage
{
    public const int MAX_SIZE_KB = 5120;

    public function available(): bool
    {
        return match (config('payment-receipts.storage')) {
            'local' => true,
            'supabase' => is_string(config('payment-receipts.supabase.url'))
                && str_starts_with(config('payment-receipts.supabase.url'), 'https://')
                && filled(config('payment-receipts.supabase.secret_key'))
                && filled(config('payment-receipts.supabase.bucket')),
            default => false,
        };
    }

    /** @return array{receipt_storage: string, receipt_bucket: ?string, receipt_path: string, receipt_name: string, receipt_mime_type: string, receipt_size: int, receipt_sha256: string} */
    public function store(UploadedFile $file): array
    {
        if (! $this->available()) {
            throw new RuntimeException('Receipt storage is not configured.');
        }

        $storage = config('payment-receipts.storage');
        $path = 'payment-receipts/'.Str::uuid().'.'.$file->extension();
        $contents = $file->getContent();
        $name = basename(str_replace('\\', '/', $file->getClientOriginalName()));
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name);
        $metadata = [
            'receipt_storage' => $storage,
            'receipt_bucket' => $storage === 'supabase' ? config('payment-receipts.supabase.bucket') : null,
            'receipt_path' => $path,
            'receipt_name' => mb_substr($name, 0, 255),
            'receipt_mime_type' => $file->getMimeType(),
            'receipt_size' => strlen($contents),
            'receipt_sha256' => hash('sha256', $contents),
        ];

        try {
            if ($storage === 'local') {
                if (! Storage::disk(config('payment-receipts.local_disk'))->put($path, $contents)) {
                    throw new RuntimeException('The receipt could not be stored.');
                }
            } else {
                $response = $this->supabase()->withBody($contents, $metadata['receipt_mime_type'])
                    ->withHeaders(['x-upsert' => 'false'])
                    ->post($this->objectUrl($metadata['receipt_bucket'], $path));

                if (! $response->successful()) {
                    throw new RuntimeException('The Supabase receipt upload failed.');
                }
            }
        } catch (Throwable $exception) {
            $this->delete($metadata);
            throw $exception;
        }

        return $metadata;
    }

    public function contents(Payment $payment): ?string
    {
        if (! preg_match('/^payment-receipts\/[a-f0-9-]{36}\.(?:jpg|jpeg|png|pdf)$/i', $payment->receipt_path ?? '')) {
            return null;
        }

        if ($payment->receipt_storage === 'local') {
            return Storage::disk(config('payment-receipts.local_disk'))->get($payment->receipt_path);
        }

        if ($payment->receipt_storage !== 'supabase') {
            throw new RuntimeException('Unknown receipt storage provider.');
        }

        $response = $this->supabase()->get($this->objectUrl($payment->receipt_bucket, $payment->receipt_path, authenticated: true));

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw new RuntimeException('The Supabase receipt download failed.');
        }

        return $response->body();
    }

    /** @param array{receipt_storage: string, receipt_bucket: ?string, receipt_path: string} $metadata */
    public function delete(array $metadata): void
    {
        try {
            if ($metadata['receipt_storage'] === 'local') {
                if (! Storage::disk(config('payment-receipts.local_disk'))->delete($metadata['receipt_path'])) {
                    throw new RuntimeException('Receipt cleanup failed.');
                }
            } else {
                $response = $this->supabase()->delete($this->objectUrl($metadata['receipt_bucket']), ['prefixes' => [$metadata['receipt_path']]]);

                if (! $response->successful()) {
                    throw new RuntimeException('Supabase receipt cleanup failed.');
                }
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    private function supabase(): PendingRequest
    {
        if (! filled(config('payment-receipts.supabase.url')) || ! filled(config('payment-receipts.supabase.secret_key'))) {
            throw new RuntimeException('Supabase receipt storage is not configured.');
        }

        return Http::withHeaders(['apikey' => config('payment-receipts.supabase.secret_key')])
            ->connectTimeout(5)->timeout(20)->withoutRedirecting();
    }

    private function objectUrl(string $bucket, ?string $path = null, bool $authenticated = false): string
    {
        $encodedPath = $path === null ? '' : '/'.implode('/', array_map('rawurlencode', explode('/', $path)));

        return rtrim(config('payment-receipts.supabase.url'), '/').'/storage/v1/object/'
            .($authenticated ? 'authenticated/' : '').rawurlencode($bucket).$encodedPath;
    }
}
