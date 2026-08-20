<?php

use Pinoox\Pinroll\Transport\FtpUploader;

test('ftp transfer timeout scales with file size and stays within bounds', function () {
    expect(FtpUploader::transferTimeoutForSize(0))->toBe(FtpUploader::TRANSFER_TIMEOUT);
    expect(FtpUploader::transferTimeoutForSize(1024))->toBe(FtpUploader::TRANSFER_TIMEOUT);
    expect(FtpUploader::transferTimeoutForSize(80 * 1024 * 1024))->toBeGreaterThan(FtpUploader::TRANSFER_TIMEOUT);
    expect(FtpUploader::transferTimeoutForSize(500 * 1024 * 1024))->toBe(3600);
});
