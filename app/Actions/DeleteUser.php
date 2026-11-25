<?php

namespace App\Actions;

use App\Enums\UserRole;
use App\Models\Automation;
use App\Models\Block;
use App\Models\Contact;
use App\Models\ContactAttribute;
use App\Models\ContactImport;
use App\Models\EmailCredential;
use App\Models\EmailIdentity;
use App\Models\EmailTemplate;
use App\Models\MailList;
use App\Models\Message;
use App\Models\Newsletter;
use App\Models\Project;
use App\Models\Sequence;
use App\Models\Tag;
use App\Models\TestMail;
use App\Models\Topic;
use App\Models\User;
use App\Models\VisualAutomation;
use App\Models\WebhookEndpoint;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DeleteUser
{
    /**
     * Models whose `user_id` column names the author of client data. The data
     * belongs to the client, so it moves to another admin when the author
     * leaves. Most of these foreign keys cascade on delete.
     *
     * @var list<class-string<Model>>
     */
    public const AUTHORED_MODELS = [
        Automation::class,
        Block::class,
        Contact::class,
        ContactAttribute::class,
        ContactImport::class,
        EmailCredential::class,
        EmailIdentity::class,
        EmailTemplate::class,
        MailList::class,
        Message::class,
        Newsletter::class,
        Project::class,
        Sequence::class,
        Tag::class,
        TestMail::class,
        Topic::class,
        VisualAutomation::class,
        WebhookEndpoint::class,
    ];

    /**
     * Delete the user. When the client has other members, the data that the
     * user created first moves to the oldest other active admin. When the user
     * is the only member, the foreign keys delete that data with the user.
     *
     * @throws ValidationException when the user is the instance owner, or the
     *                             only active admin of a client with other members.
     */
    public function handle(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $successor = $this->successorFor($user);

            if ($successor !== null) {
                foreach (self::AUTHORED_MODELS as $model) {
                    $model::withoutGlobalScopes()
                        ->where('user_id', $user->id)
                        ->update(['user_id' => $successor->id]);
                }
            }

            $user->delete();
        });
    }

    /**
     * Return the admin who receives the data of the user, or null when the
     * user is the only member of the client.
     *
     * @throws ValidationException
     */
    public function successorFor(User $user, string $errorKey = 'password'): ?User
    {
        if ($user->isOwner()) {
            throw ValidationException::withMessages([
                $errorKey => 'The instance owner cannot delete the account.',
            ]);
        }

        $hasTeammates = User::query()
            ->where('client_id', $user->client_id)
            ->whereKeyNot($user->id)
            ->exists();

        if (! $hasTeammates) {
            return null;
        }

        $successor = User::query()
            ->where('client_id', $user->client_id)
            ->whereKeyNot($user->id)
            ->where('role', UserRole::Admin->value)
            ->whereNull('archived_at')
            ->whereNull('deactivated_at')
            ->oldest('id')
            ->first();

        if ($successor === null) {
            throw ValidationException::withMessages([
                $errorKey => 'Make another team member an admin before you delete your account.',
            ]);
        }

        return $successor;
    }
}
