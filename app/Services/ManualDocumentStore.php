<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The private holding place for the documents a customer uploads with an
 * identity request.
 *
 * A document has to be safe on disk before the wallet is touched, and it has to
 * end up filed against the order that paid for it - so the write happens twice,
 * once into a temporary holding folder that belongs to no order, and once into
 * the order's own folder once that order exists. Nothing in between is allowed
 * to leave a paid request without the document it paid for.
 *
 * The disk is the private one, outside the web root, so a file is only ever
 * readable through an authenticated admin route.
 */
class ManualDocumentStore
{
    private const DISK = 'local';

    private const PENDING = 'manual-uploads/.pending';

    private const FILED = 'manual-uploads';

    /** A fresh holding area for one submission attempt. */
    public function token(): string
    {
        return (string) Str::random(32);
    }

    /**
     * Keep one attached document off the order until the money is settled.
     * Returns the stored path, or null when the disk refused to keep it.
     */
    public function stage(string $token, string $field, UploadedFile $file): ?string
    {
        $path = $file->storeAs(
            self::PENDING.'/'.$token,
            $field.'-'.Str::random(24).'.'.$this->extension($file),
            self::DISK,
        );

        if (!is_string($path) || !Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        return $path;
    }

    /**
     * Move a held document into the folder of the order that paid for it.
     * Returns the final path, or null when the move did not land.
     */
    public function publish(string $token, string $path, int $orderId): ?string
    {
        $disk = Storage::disk(self::DISK);
        $target = self::FILED.'/'.$orderId.'/'.basename($path);

        if (!$disk->move($path, $target) || !$disk->exists($target)) {
            return null;
        }

        return $target;
    }

    /**
     * Throw away a held or filed document. Failure is silent because this runs
     * on the way out of a request that has already been refused or reversed;
     * a leftover file is a disk-space problem, never a money problem.
     */
    public function discard(?string $path = null, ?string $token = null): void
    {
        $disk = Storage::disk(self::DISK);

        try {
            if (is_string($path) && $path !== '') {
                $disk->delete($path);
            }

            if (is_string($token) && $token !== '') {
                $disk->deleteDirectory(self::PENDING.'/'.$token);
            }
        } catch (\Throwable) {
            //
        }
    }

    /**
     * Remove every document filed against one order. The folder belongs to that
     * order alone, so this is what a closed request leaves behind: nothing. It
     * also covers the case a caller cannot cover itself - a document that
     * reached the order folder a moment before the code was told it did not.
     */
    public function discardOrder(int $orderId): void
    {
        try {
            Storage::disk(self::DISK)->deleteDirectory(self::FILED.'/'.$orderId);
        } catch (\Throwable) {
            //
        }
    }

    /**
     * Clear the holding folders a request that died mid-flight left behind. A
     * staged document carries the customer's identity data, so it must not sit
     * around unclaimed - and this runs before any money moves, so a failure
     * here is never allowed to break a submission.
     *
     * Age alone does not make a folder unclaimed. A request whose payment is
     * already booked but whose filing was interrupted still owns the paper in it,
     * so the caller names the folders a paid order is still waiting on and those
     * are left exactly where they are.
     *
     * @param  list<string>  $stillNeeded
     */
    public function pruneHolding(int $olderThanMinutes = 60, array $stillNeeded = []): void
    {
        try {
            $disk = Storage::disk(self::DISK);
            $cutoff = time() - ($olderThanMinutes * 60);
            $needed = array_flip($stillNeeded);

            foreach ($disk->directories(self::PENDING) as $directory) {
                if ($disk->lastModified($directory) >= $cutoff) {
                    continue;
                }

                if (isset($needed[basename($directory)])) {
                    continue;
                }

                $disk->deleteDirectory($directory);
            }
        } catch (\Throwable) {
            //
        }
    }

    /** Whether held paper is still where the manifest says it is. */
    public function isHeld(string $path): bool
    {
        return Storage::disk(self::DISK)->exists($path);
    }

    /**
     * Paper that already reached the order's folder without the order being told.
     *
     * A move cannot be rolled back with the database: a request that died between
     * the two leaves the file at its destination and the record still pointing at
     * the holding folder. Recovery has to be able to find that copy, or it would
     * call paid-for paper lost while it is sitting in the right place.
     */
    public function alreadyFiled(int $orderId, string $heldPath): ?string
    {
        if ($heldPath === '') {
            return null;
        }

        $target = self::FILED.'/'.$orderId.'/'.basename($heldPath);

        return Storage::disk(self::DISK)->exists($target) ? $target : null;
    }

    private function extension(UploadedFile $file): string
    {
        foreach ([
            strtolower((string) $file->getClientOriginalExtension()),
            strtolower((string) pathinfo((string) $file->hashName(), PATHINFO_EXTENSION)),
        ] as $candidate) {
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return 'bin';
    }
}
