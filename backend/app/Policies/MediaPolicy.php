<?php

namespace App\Policies;

use App\Models\Media;
use App\Models\User;

/**
 * Every content type that currently owns an image field (catalog products,
 * CMS pages/articles/homepage blocks) is super_admin/agen-managed only — see
 * ProductPolicy/HomepageBlockController. Media inherits the same gate rather
 * than inventing a separate one. The one exception is 'user_avatar' — every
 * authenticated role manages their own profile photo, checked here per
 * collection rather than widening the gate for every other collection too.
 */
class MediaPolicy
{
    public function create(User $user, ?string $collection = null): bool
    {
        if ($collection === 'user_avatar') {
            return true;
        }

        return $user->isRole('super_admin', 'agen');
    }

    public function delete(User $user, Media $media): bool
    {
        if ($media->collection === 'user_avatar') {
            return $media->created_by === $user->id;
        }

        return $user->isRole('super_admin', 'agen');
    }
}
