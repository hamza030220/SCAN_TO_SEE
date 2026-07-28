<?php

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Proxies scan requests to the FastAPI OCR pipeline.
 */
class MenuScannerClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly string $ocrPipelineUrl,
    ) {}

    /**
     * Send one image to the OCR pipeline and return decoded JSON.
     *
     * @throws \RuntimeException
     */
    public function scanMenu(UploadedFile $image, string $currency = 'TND'): array
    {
        $formData = new FormDataPart([
            'currency' => $currency,
            'image'    => DataPart::fromPath(
                $image->getPathname(),
                $image->getClientOriginalName() ?? 'menu.jpg',
                $image->getMimeType() ?? 'image/jpeg',
            ),
        ]);

        try {
            $response = $this->httpClient->request(
                'POST',
                rtrim($this->ocrPipelineUrl, '/') . '/scan-menu',
                [
                    'headers' => $formData->getPreparedHeaders()->toArray(),
                    'body'    => $formData->bodyToIterable(),
                    // First model load plus crop uploads can exceed 90 seconds
                    // on the local CUDA/XAMPP development stack.
                    'timeout' => 240,
                ]
            );
            $statusCode = $response->getStatusCode();
            $content    = $response->toArray(false);
        } catch (\Throwable $e) {
            throw new \RuntimeException('The scan service is temporarily unavailable. Please try again.', 0, $e);
        }

        if ($statusCode === 400) {
            throw new \RuntimeException($content['message'] ?? 'Image could not be read.');
        }
        if ($statusCode >= 500) {
            throw new \RuntimeException('Scan pipeline error. Please try again.');
        }
        if ($statusCode >= 422) {
            throw new \RuntimeException($content['message'] ?? 'Image processing failed. Try a clearer photo.');
        }

        return $content;
    }
}
