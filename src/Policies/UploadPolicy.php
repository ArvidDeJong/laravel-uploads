<?php

namespace Darvis\Uploads\Policies;

use Darvis\Uploads\Models\Upload;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Who may open a private upload through the download route.
 *
 * The default allows the person who uploaded the file, the owner of the model the
 * upload belongs to (a `user_id` on that model) and an administrator (a truthy
 * `is_admin` attribute). Register your own policy for `Upload` to change this.
 */
class UploadPolicy
{
    public function view(?Authenticatable $user, Upload $upload): bool
    {
        if ($user === null) {
            return false;
        }

        $id = $user->getAuthIdentifier();

        if ($upload->user_id !== null && (string) $upload->user_id === (string) $id) {
            return true;
        }

        if ($user instanceof Model && $user->getAttribute('is_admin')) {
            return true;
        }

        $owner = $upload->uploadable;

        return $owner instanceof Model
            && $owner->getAttribute('user_id') !== null
            && (string) $owner->getAttribute('user_id') === (string) $id;
    }

    public function delete(?Authenticatable $user, Upload $upload): bool
    {
        return $this->view($user, $upload);
    }
}
