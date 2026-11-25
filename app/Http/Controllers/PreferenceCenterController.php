<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\ContactMail;
use App\Models\TestMail;
use App\Models\Topic;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class PreferenceCenterController extends Controller
{
    public function show(Request $request, string $contactMail): Response
    {
        [$contactMailModel, $contactModel, $isTestMail] = $this->resolveContactMail($contactMail);

        $listIds = $contactModel->lists()->pluck('lists.id')->all();

        $topics = Topic::withoutGlobalScopes()
            ->whereIn('list_id', $listIds)
            ->with(['list:id,name'])
            ->orderBy('list_id')
            ->orderBy('name')
            ->get();

        $subscriptions = DB::table('contact_topic')
            ->where('contact_id', $contactModel->id)
            ->pluck('unsubscribed_at', 'topic_id')
            ->all();

        $payload = $topics->map(fn (Topic $topic): array => [
            'id' => $topic->id,
            'name' => $topic->name,
            'description' => $topic->description,
            'list_name' => $topic->list?->name,
            'subscribed' => array_key_exists($topic->id, $subscriptions)
                ? $subscriptions[$topic->id] === null
                : true,
        ])->all();

        return Inertia::render('preferences/Show', [
            'contactMailUuid' => $contactMail,
            'isTestMail' => $isTestMail,
            'contact' => [
                'uuid' => $contactModel->uuid,
                'email' => $contactModel->email,
                'name' => $contactModel->name,
            ],
            'topics' => $payload,
            'unsubscribedFromAll' => $contactModel->unsubscribed_at !== null,
        ]);
    }

    public function update(Request $request, string $contactMail): RedirectResponse
    {
        [, $contactModel, $isTestMail] = $this->resolveContactMail($contactMail);

        if ($isTestMail) {
            return back()->with('status', 'Test preview — no changes saved');
        }

        $request->validate([
            'subscribed_topic_ids' => ['array'],
            'subscribed_topic_ids.*' => ['integer'],
        ]);

        $listIds = $contactModel->lists()->pluck('lists.id')->all();

        $allTopicIds = Topic::withoutGlobalScopes()
            ->whereIn('list_id', $listIds)
            ->pluck('id')
            ->all();

        $subscribed = array_values(array_intersect(
            array_map('intval', $request->input('subscribed_topic_ids', [])),
            $allTopicIds,
        ));

        $now = now();

        $existingIds = DB::table('contact_topic')
            ->where('contact_id', $contactModel->id)
            ->pluck('topic_id')
            ->all();

        foreach ($allTopicIds as $topicId) {
            $isSubscribed = in_array($topicId, $subscribed, true);
            $payload = [
                'subscribed_at' => $isSubscribed ? $now : null,
                'unsubscribed_at' => $isSubscribed ? null : $now,
                'updated_at' => $now,
            ];

            if (in_array($topicId, $existingIds, true)) {
                DB::table('contact_topic')
                    ->where('contact_id', $contactModel->id)
                    ->where('topic_id', $topicId)
                    ->update($payload);
            } else {
                DB::table('contact_topic')->insert(array_merge($payload, [
                    'contact_id' => $contactModel->id,
                    'topic_id' => $topicId,
                    'created_at' => $now,
                ]));
            }
        }

        if (! empty($subscribed) && $contactModel->unsubscribed_at !== null) {
            $contactModel->forceFill([
                'unsubscribed_at' => null,
                'unsubscribed_by_contact' => false,
            ])->save();
        }

        return back()->with('status', 'Preferences updated');
    }

    public function destroy(Request $request, string $contactMail): RedirectResponse
    {
        [, $contactModel, $isTestMail] = $this->resolveContactMail($contactMail);

        if ($isTestMail) {
            return back()->with('status', 'Test preview — no changes saved');
        }

        $contactModel->forceFill([
            'unsubscribed_at' => now(),
            'unsubscribed_by_contact' => true,
        ])->save();

        DB::table('contact_topic')
            ->where('contact_id', $contactModel->id)
            ->update(['unsubscribed_at' => now()]);

        return back()->with('status', 'Unsubscribed from all emails');
    }

    public function resubscribe(Request $request, string $contactMail): RedirectResponse
    {
        [, $contactModel, $isTestMail] = $this->resolveContactMail($contactMail);

        if ($isTestMail) {
            return back()->with('status', 'Test preview — no changes saved');
        }

        $contactModel->forceFill([
            'unsubscribed_at' => null,
            'unsubscribed_by_contact' => false,
        ])->save();

        $now = now();

        DB::table('contact_topic')
            ->where('contact_id', $contactModel->id)
            ->whereNotNull('unsubscribed_at')
            ->update([
                'unsubscribed_at' => null,
                'subscribed_at' => $now,
                'updated_at' => $now,
            ]);

        return back()->with('status', 'Resubscribed to all emails');
    }

    /**
     * @return array{ContactMail, Contact, bool}
     */
    private function resolveContactMail(string $contactMailUuid): array
    {
        $contactMail = ContactMail::withoutGlobalScopes()
            ->where('uuid', $contactMailUuid)
            ->firstOrFail();

        $contact = Contact::withoutGlobalScopes()
            ->where('id', $contactMail->contact_id)
            ->firstOrFail();

        $isTestMail = $contactMail->mailable_type === TestMail::class;

        return [$contactMail, $contact, $isTestMail];
    }
}
