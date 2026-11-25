# BYOMailer API Documentation

Integrate with the BYOMailer API to manage contacts, tags, and mailing lists programmatically.

## Getting Started

1. **Create an account.** Sign up or log in so you can access the dashboard.
2. **Generate an API token.** Go to **Settings → API Tokens** in the dashboard and create a new token. The value is shown only once, so copy it somewhere secure.
3. **Start making requests.** Include your API token in the `Authorization` header of every API request. You can [download the Postman collection](/documentation/postman) to explore the endpoints quickly.

## Authentication

All API requests must be authenticated with a Bearer token that you create in your account settings.

### Token Access

Each token has one access level. You choose it when you create the token.

- **Read only.** The token can read data. A request that changes data or sends mail gets `403 Forbidden`.
- **Read and write.** The token can read and change data.

Tokens made before access levels existed have read and write access. A token can also have an expiration date. After that date, the API answers `401 Unauthenticated`.

A team member with the Viewer role has read-only access. Each token of a Viewer is read only.

### Authorization Header

```
Authorization: Bearer your-api-token-here
```

### Base URL

Use `https://byomailer.example/api/v1` as the base URL for all API requests.

## Contacts

### GET `/api/v1/contacts`

Retrieve a paginated list of contacts for the authenticated client. Supports optional filters.

**Request**

Headers

```json
{
  "Authorization": "Bearer {your-api-token}",
  "Accept": "application/json"
}
```

Query Parameters

| Parameter             | Type      | Description                                                      |
|-----------------------|-----------|------------------------------------------------------------------|
| lists[]               | int[]     | Filter to contacts that belong to any of the given list IDs.     |
| tags[]                | int[]     | Filter to contacts that have any of the given tag IDs.           |
| topics[]              | int[]     | Filter to contacts subscribed to any of the given topic IDs.     |
| search                | string    | Partial match on `name` or `email`.                              |
| include_unsubscribed  | bool      | When `true`, include unsubscribed contacts. Default `false`.     |
| per_page              | int       | Results per page (default 25, max 100).                          |
| cursor                | string    | Cursor for the next/previous page (see Pagination section). |

**Response – 200**

```json
{
  "data": [
    {
      "id": 1,
      "uuid": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
      "name": "John Doe",
      "email": "john@example.com",
      "extra_attributes": { "company": "Acme Inc" },
      "unsubscribed_at": null,
      "unsubscribed_by_contact": false,
      "tags": [
        { "id": 1, "uuid": "tag-uuid-1", "name": "newsletter", "created_at": "2024-01-15T10:30:00.000000Z" }
      ],
      "lists": [
        { "id": 1, "uuid": "list-uuid-1", "name": "Weekly Newsletter", "created_at": "2024-01-10T08:00:00.000000Z" }
      ],
      "topics": [
        { "id": 1, "uuid": "topic-uuid-1", "list_id": 1, "name": "Product Updates", "description": null, "created_at": "2024-01-15T10:30:00.000000Z" }
      ],
      "sequences": [
        { "id": 3, "name": "Onboarding", "status": "live" }
      ],
      "created_at": "2024-01-15T10:30:00.000000Z"
    }
  ],
  "meta": {
    "path": "https://byomailer.example/api/v1/contacts",
    "per_page": 25,
    "next_cursor": "eyJpZCI6MTAxLCJfcG9pbnRzVG9OZXh0SXRlbXMiOnRydWV9",
    "prev_cursor": null
  }
}
```

### GET `/api/v1/contacts/{contactId}`

Retrieve a single contact by ID, including tags, lists, topics, and sequences.

**Request**

Headers

```json
{
  "Authorization": "Bearer {your-api-token}",
  "Accept": "application/json"
}
```

**Response – 200**

```json
{
  "data": {
    "id": 1,
    "uuid": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "name": "John Doe",
    "email": "john@example.com",
    "extra_attributes": { "company": "Acme Inc", "phone": "+1234567890" },
    "unsubscribed_at": null,
    "unsubscribed_by_contact": false,
    "tags": [
      { "id": 1, "uuid": "tag-uuid-1", "name": "newsletter", "created_at": "2024-01-15T10:30:00.000000Z" }
    ],
    "lists": [
      { "id": 1, "uuid": "list-uuid-1", "name": "Weekly Newsletter", "created_at": "2024-01-10T08:00:00.000000Z" }
    ],
    "topics": [
      { "id": 1, "uuid": "topic-uuid-1", "name": "Product Updates" }
    ],
    "sequences": [
      { "id": 3, "name": "Onboarding", "status": "live" }
    ],
    "created_at": "2024-01-15T10:30:00.000000Z"
  }
}
```

### POST `/api/v1/lists/{listId}/contacts`

Create a contact (or update an existing one) and attach it to a list. The subscription never changes: an unsubscribed contact stays unsubscribed. To subscribe it again, use `POST /api/v1/contacts/{contactId}/subscribe`.

**Request**

Headers

```json
{
  "Authorization": "Bearer {your-api-token}",
  "Content-Type": "application/json",
  "Accept": "application/json"
}
```

Body

```json
{
  "email": "john@example.com",
  "name": "John Doe",
  "tags": ["newsletter", "customer"],
  "company": "Acme Inc",
  "phone": "+1234567890"
}
```

**Response – 201**

```json
{
  "data": {
    "id": 1,
    "uuid": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "name": "John Doe",
    "email": "john@example.com",
    "extra_attributes": {
      "company": "Acme Inc",
      "phone": "+1234567890"
    },
    "unsubscribed_at": null,
    "unsubscribed_by_contact": false,
    "tags": [
      {
        "id": 1,
        "uuid": "tag-uuid-1",
        "name": "newsletter",
        "created_at": "2024-01-15T10:30:00.000000Z"
      },
      {
        "id": 2,
        "uuid": "tag-uuid-2",
        "name": "customer",
        "created_at": "2024-01-15T10:30:00.000000Z"
      }
    ],
    "lists": [
      {
        "id": 1,
        "uuid": "list-uuid-1",
        "name": "Weekly Newsletter",
        "created_at": "2024-01-10T08:00:00.000000Z"
      }
    ],
    "created_at": "2024-01-15T10:30:00.000000Z"
  }
}
```

### PUT `/api/v1/contacts/{contactId}`

Update a contact by ID.

**Request**

Headers

```json
{
  "Authorization": "Bearer {your-api-token}",
  "Content-Type": "application/json",
  "Accept": "application/json"
}
```

Body

```json
{
  "name": "John Smith",
  "tags": ["vip"],
  "remove_tags": ["trial"]
}
```

Tag fields (this endpoint and `POST /api/v1/lists/{listId}/contacts`):

| Field         | Type     | Description |
|---------------|----------|-------------|
| `tags`        | string[] | Tag names to add. The API creates a tag that does not exist. The contact keeps its other tags. |
| `remove_tags` | string[] | Optional. Tag names to remove from the contact. A name that has no tag, or a tag that the contact does not have, is ignored. |

- The API adds and removes the tags in one change. Automations with the "tag added" or "tag removed" condition and the tag filters of sequences react the same as for a change in the dashboard.
- If the same tag name is in `tags` and in `remove_tags`, the request fails with `422`. The comparison ignores case and spaces at the start and end.
- If you do not send `remove_tags`, no tag is removed.

An update never changes the subscription. To subscribe a contact again, use `POST /api/v1/contacts/{contactId}/subscribe` or add the contact to a list.

**Response – 200**

```json
{
  "data": {
    "id": 1,
    "uuid": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "name": "John Smith",
    "email": "john@example.com",
    "extra_attributes": {
      "company": "Acme Inc",
      "phone": "+1234567890"
    },
    "unsubscribed_at": null,
    "unsubscribed_by_contact": false,
    "tags": [
      {
        "id": 3,
        "uuid": "tag-uuid-3",
        "name": "vip",
        "created_at": "2024-01-15T10:30:00.000000Z"
      },
      {
        "id": 2,
        "uuid": "tag-uuid-2",
        "name": "customer",
        "created_at": "2024-01-15T10:30:00.000000Z"
      }
    ],
    "lists": [
      {
        "id": 1,
        "uuid": "list-uuid-1",
        "name": "Weekly Newsletter",
        "created_at": "2024-01-10T08:00:00.000000Z"
      }
    ],
    "created_at": "2024-01-15T10:30:00.000000Z"
  }
}
```

### POST `/api/v1/contacts/{contactId}/unsubscribe`

Unsubscribe a contact. The contact is marked as unsubscribed and will no longer receive emails.

**Request**

Headers

```json
{
  "Authorization": "Bearer {your-api-token}",
  "Accept": "application/json"
}
```

**Response – 200**

```json
{
  "data": {
    "id": 1,
    "uuid": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "name": "John Doe",
    "email": "john@example.com",
    "extra_attributes": {},
    "unsubscribed_at": "2024-01-16T14:00:00.000000Z",
    "unsubscribed_by_contact": false,
    "tags": [],
    "lists": [
      {
        "id": 1,
        "uuid": "list-uuid-1",
        "name": "Weekly Newsletter",
        "created_at": "2024-01-10T08:00:00.000000Z"
      }
    ],
    "created_at": "2024-01-15T10:30:00.000000Z"
  }
}
```


### POST `/api/v1/contacts/{contactId}/subscribe`

Subscribe a contact again. The request clears an unsubscribe that an admin or the API made. The contact then gets newsletters and sequence emails again.

- If the contact unsubscribed through an email link, the request fails with `422`. Only the contact can subscribe again.
- If the address is on the suppression list after a complaint, or on the global suppression list, the request fails with `422`.
- If the address has a bounce suppression of your client, the request fails with `422`. Send `"remove_suppression": true` to remove the suppression and subscribe the contact.
- If the contact is subscribed, nothing changes.

**Request**

Headers

```json
{
  "Authorization": "Bearer {your-api-token}",
  "Accept": "application/json"
}
```

**Response – 200**

```json
{
  "data": {
    "id": 1,
    "uuid": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "name": "John Doe",
    "email": "john@example.com",
    "extra_attributes": {},
    "unsubscribed_at": null,
    "unsubscribed_by_contact": false,
    "tags": [],
    "lists": [],
    "created_at": "2024-01-15T10:30:00.000000Z"
  }
}
```

**Response – 422**

```json
{
  "message": "The contact unsubscribed through an email link. Only the contact can subscribe again.",
  "errors": {
    "contact": ["The contact unsubscribed through an email link. Only the contact can subscribe again."]
  }
}
```

### DELETE `/api/v1/lists/{listId}/contacts/{contactId}`

Detach a contact from a list without deleting the contact.

**Request**

Headers

```json
{
  "Authorization": "Bearer {your-api-token}",
  "Accept": "application/json"
}
```

**Response – 200**

```json
{
  "data": {
    "id": 1,
    "uuid": "a1b2c3d4-e5f6-7890-abcd-ef1234567890",
    "name": "John Doe",
    "email": "john@example.com",
    "extra_attributes": {},
    "unsubscribed_at": null,
    "unsubscribed_by_contact": false,
    "tags": [],
    "lists": [],
    "created_at": "2024-01-15T10:30:00.000000Z"
  }
}
```

## Tags

### GET `/api/v1/tags`

Return every tag owned by the authenticated user.

**Request**

Headers

```json
{
  "Authorization": "Bearer {your-api-token}",
  "Accept": "application/json"
}
```

**Response – 200**

```json
{
  "data": [
    {
      "id": 1,
      "uuid": "tag-uuid-1",
      "name": "newsletter",
      "created_at": "2024-01-15T10:30:00.000000Z"
    },
    {
      "id": 2,
      "uuid": "tag-uuid-2",
      "name": "customer",
      "created_at": "2024-01-14T09:00:00.000000Z"
    }
  ]
}
```

### POST `/api/v1/tags`

Create a tag.

**Request**

Headers

```json
{
  "Authorization": "Bearer {your-api-token}",
  "Content-Type": "application/json",
  "Accept": "application/json"
}
```

Body

```json
{
  "name": "premium"
}
```

**Response – 201**

```json
{
  "data": {
    "id": 3,
    "uuid": "tag-uuid-3",
    "name": "premium",
    "created_at": "2024-01-16T11:00:00.000000Z"
  }
}
```

### PATCH `/api/v1/tags/{tag}`

Rename a tag. Tag filters select tags by name. When a saved filter of a newsletter that can still send, a sequence, a sequence step or a segment uses the tag, the request fails with `422`.

**Request**

Headers

```json
{
  "Authorization": "Bearer {your-api-token}",
  "Content-Type": "application/json",
  "Accept": "application/json"
}
```

Body

```json
{
  "name": "premium-user"
}
```

**Response – 200**

```json
{
  "data": {
    "id": 3,
    "uuid": "tag-uuid-3",
    "name": "premium-user",
    "created_at": "2024-01-16T11:00:00.000000Z"
  }
}
```

### DELETE `/api/v1/tags/{tag}`

Delete a tag and remove it from each contact. The removal runs the live tag automations. When a saved filter uses the tag, the request fails with `422`.

**Request**

Headers

```json
{
  "Authorization": "Bearer {your-api-token}",
  "Accept": "application/json"
}
```

**Response – 204**

```json
{}
```

## Lists

### GET `/api/v1/lists`

Return all lists owned by the authenticated user.

**Request**

Headers

```json
{
  "Authorization": "Bearer {your-api-token}",
  "Accept": "application/json"
}
```

**Response – 200**

```json
{
  "data": [
    {
      "id": 1,
      "uuid": "list-uuid-1",
      "name": "Weekly Newsletter",
      "created_at": "2024-01-10T08:00:00.000000Z"
    },
    {
      "id": 2,
      "uuid": "list-uuid-2",
      "name": "Product Updates",
      "created_at": "2024-01-12T15:30:00.000000Z"
    }
  ]
}
```

### POST `/api/v1/lists`

Create a new list.

**Request**

Headers

```json
{
  "Authorization": "Bearer {your-api-token}",
  "Content-Type": "application/json",
  "Accept": "application/json"
}
```

Body

```json
{
  "name": "Beta Testers"
}
```

A list name is unique in your account. A duplicate name returns `422` with an error on `name`.

**Response – 201**

```json
{
  "data": {
    "id": 3,
    "uuid": "list-uuid-3",
    "name": "Beta Testers",
    "created_at": "2024-01-16T12:00:00.000000Z"
  }
}
```

### PATCH `/api/v1/lists/{listId}`

Update an existing list.

**Request**

Headers

```json
{
  "Authorization": "Bearer {your-api-token}",
  "Content-Type": "application/json",
  "Accept": "application/json"
}
```

Body

```json
{
  "name": "Early Access Users"
}
```

**Response – 200**

```json
{
  "data": {
    "id": 3,
    "uuid": "list-uuid-3",
    "name": "Early Access Users",
    "created_at": "2024-01-16T12:00:00.000000Z"
  }
}
```

### DELETE `/api/v1/lists/{listId}`

Delete a list and its topics. The contacts on the list stay.

When a newsletter that is not sent, a sequence, a sequence step, a segment or an automation uses the list or one of its topics, the request fails with `422`. The error names each item. Remove the list from these items first.

**Request**

Headers

```json
{
  "Authorization": "Bearer {your-api-token}",
  "Accept": "application/json"
}
```

**Response – 204**

```json
{}
```

**Response – 422**

```json
{
  "message": "These items use the list or one of its topics: newsletter \"Spring sale\" (#4), segment \"Engaged buyers\" (#2). Remove the list and its topics from them, then delete the list.",
  "errors": {
    "list": [
      "These items use the list or one of its topics: newsletter \"Spring sale\" (#4), segment \"Engaged buyers\" (#2). Remove the list and its topics from them, then delete the list."
    ]
  }
}
```

## Newsletters

Newsletters are email campaigns that can be created, updated, and sent via the API.

### Filters

Filters let you target specific contacts when sending a newsletter. Each filter narrows the recipient list by tags, lists, topics, contact attributes, signup date, newsletter engagement, or location. Multiple filters can be combined with `"and"` or `"or"` connectors.

Responses also include a read-only `segment_id`. When a newsletter or sequence targets a saved segment (set via the dashboard), `segment_id` is non-null and the `filters` array reflects that segment's currently-resolved audience. Segments cannot be assigned through the API yet — send inline `filters` instead.

Each filter object has the following fields:

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `connector` | string | No | `"and"` or `"or"`. Defaults to `"and"`. Ignored on the first filter. |
| `category` | string | Yes | `"tags"`, `"lists"`, `"topics"`, `"attributes"`, `"date"`, `"engagement"`, or `"location"`. |
| `mode` | string | Conditional | Required for `tags`/`lists`/`topics`/`attributes` (`"all"`, `"any"`, `"none"`) and `location` (`"any"`, `"none"`). Omitted for `date`/`engagement`. |
| `operator` | string | Conditional | Required for `date` and `engagement` (see below). Omitted for mode categories. |
| `field` | string | Conditional | Required for `location`: `"country"` or `"timezone"`. |
| `values` | array/object | Yes | Values to match. Format depends on category (see below). |

**Mode categories** (`mode` = `all` / `any` / `none`):

- **tags** — Array of tag name strings: `["vip", "premium"]`
- **lists** — Array of list ID integers: `[1, 5]`
- **topics** — Array of topic ID integers: `[12, 34]`
- **attributes** — Array of `{key, operator, value}` objects. `operator` is one of `equals`, `not_equals`, `contains`, `not_contains`, `gt`, `lt`, `is_set`, `is_not_set`. `value` is ignored for `is_set`/`is_not_set` and must be numeric for `gt`/`lt`. Example: `[{"key": "plan", "operator": "equals", "value": "pro"}]`
- **location** — `field` selects `"country"` (ISO country codes) or `"timezone"`. `mode` is `"any"` or `"none"` only. `values` is an array of strings: `["US", "GB"]`

**Operator categories:**

- **date** — Filters on the contact's signup date. `operator`:
  - `before` / `after` — `values` = `{"date": "YYYY-MM-DD"}`
  - `in_last` / `more_than` — `values` = `{"days": 30}` (relative to now)
  - `between` — `values` = `{"from": "YYYY-MM-DD", "to": "YYYY-MM-DD"}`
- **engagement** — Filters on newsletter engagement. `operator` is one of `opened`, `clicked`, `received`, `not_opened`, `not_clicked`, `not_received`. `received` means the newsletter was **delivered** to the contact's inbox — a bounced send does not count. `values` = `{"newsletter_id": <id|null>, "within": "ever"|"last_n_days", "days": <int|null>}`. `newsletter_id` `null` means any newsletter; `days` is required when `within` is `last_n_days`. Negated operators (`not_*`) match contacts with no qualifying activity — this **includes contacts who never had a newsletter delivered** (cold or bounced contacts), which is intentional for re-engagement targeting. Set `newsletter_id` to scope the check to one campaign.

**Example:**

```json
{
  "filters": [
    {
      "connector": "and",
      "category": "tags",
      "mode": "any",
      "values": ["vip", "premium"]
    },
    {
      "connector": "and",
      "category": "attributes",
      "mode": "all",
      "values": [
        { "key": "age", "operator": "gt", "value": "21" }
      ]
    },
    {
      "connector": "and",
      "category": "date",
      "operator": "more_than",
      "values": { "days": 14 }
    },
    {
      "connector": "and",
      "category": "engagement",
      "operator": "not_opened",
      "values": { "newsletter_id": null, "within": "last_n_days", "days": 90 }
    },
    {
      "connector": "or",
      "category": "location",
      "field": "country",
      "mode": "any",
      "values": ["US", "GB"]
    }
  ]
}
```

### Pagination

All list endpoints use **cursor pagination** (no offset, no total page count). They accept a `per_page` query parameter and a `cursor` for the next/previous page.

- `per_page` defaults to `25` and is capped at `100`.
- The response includes `meta.next_cursor` and `meta.prev_cursor`. Pass either as `?cursor=<value>` to fetch the next/previous page. Cursor is `null` at the end.
- Cursor pagination is faster than offset pagination at scale and avoids drift when rows are inserted between requests, but it cannot jump to an arbitrary page or report the total row count.

**Example envelope**

```json
{
  "data": [ ... ],
  "meta": {
    "path": "https://byomailer.example/api/v1/newsletters",
    "per_page": 25,
    "next_cursor": "eyJpZCI6MTAxLCJfcG9pbnRzVG9OZXh0SXRlbXMiOnRydWV9",
    "prev_cursor": null
  }
}
```

### Scheduling

`scheduled_at` accepts any ISO 8601 datetime, including values up to 60 seconds in the past (clock skew tolerance). Values further in the past are rejected with a 422 validation error. `scheduled_at` is **prohibited unless `status` is `"live"`** — sending it on a draft returns 422.

### Statuses

| Status | Meaning |
|--------|---------|
| `draft` | Editable. Not scheduled. |
| `scheduled` | Editable until the scheduled time. Scheduler promotes to `queued` and dispatches when `scheduled_at` is due. |
| `queued` | In flight. Being dispatched to SES. Not editable, not deletable. |
| `sent` | Delivery batch completed. Terminal. |
| `failed` | Delivery batch errored. Terminal. |
| `ab_testing` | A/B test is running (mid-send). |

### GET `/api/v1/newsletters`

Return newsletters for the authenticated client. Paginated.

**Query parameters**

| Parameter | Type | Description |
|-----------|------|-------------|
| `status` | string | Filter by persisted status. One of `draft`, `scheduled`, `queued`, `ab_testing`, `sent`, `failed`. |
| `search` | string | Match newsletter title or message subject (substring, case-insensitive on most engines). |
| `project` | integer | Only newsletters assigned to the given project ID (see Projects section). |
| `per_page` | integer | Items per page. Defaults to `25`, capped at `100`. |
| `cursor` | string | Cursor for the next/previous page (see Pagination section). |

**Response – 200**

```json
{
  "data": [
    {
      "id": 1,
      "uuid": "newsletter-uuid-1",
      "title": "March Product Update",
      "status": "sent",
      "subject": "What's new in March",
      "preview_text": "Three new things, two hot takes, one demo",
      "email_identity_id": 1,
      "email_identity": {
        "id": 1,
        "email": "hello@example.com",
        "name": "Acme Inc",
        "domain": "example.com",
        "identity_status": "verified"
      },
      "email_template_id": null,
      "from_email": "hello@example.com",
      "from_name": "Acme Inc",
      "scheduled_at": "2024-03-01T09:00:00.000000Z",
      "sent_at": "2024-03-01T09:00:00.000000Z",
      "segment_id": null,
      "project_ids": [2],
      "filters": [
        { "connector": "and", "category": "tags", "mode": "any", "values": ["vip"] }
      ],
      "content_variables": { "first_name": "Friend" },
      "ab_test_enabled": false,
      "ab_test_winner_variant": null,
      "subject_b": null,
      "preview_text_b": null,
      "html_content_b": null,
      "text_content_b": null,
      "total_contacts_reached": 842,
      "metrics": {
        "total": 842,
        "sent": 842,
        "pending": 0,
        "opened": 301,
        "bounced": 4,
        "unsubscribed": 3,
        "complained": 0,
        "open_rate": 35.7,
        "unsubscribe_rate": 0.4
      },
      "created_at": "2024-02-28T10:00:00.000000Z",
      "updated_at": "2024-03-01T09:00:00.000000Z"
    }
  ],
  "meta": {
    "path": "https://byomailer.example/api/v1/newsletters",
    "per_page": 25,
    "next_cursor": "eyJpZCI6MTAxLCJfcG9pbnRzVG9OZXh0SXRlbXMiOnRydWV9",
    "prev_cursor": null
  }
}
```

The list endpoint omits `html_content` and `text_content` for smaller payloads — fetch the show endpoint for the full body. When the newsletter has no sends yet, `metrics.open_rate` and `metrics.unsubscribe_rate` are `0` (not `null`).

### GET `/api/v1/newsletters/{newsletterId}`

Return a single newsletter by ID.

**Request**

Headers

```json
{
  "Authorization": "Bearer {your-api-token}",
  "Accept": "application/json"
}
```

**Response – 200**

```json
{
  "data": {
    "id": 1,
    "uuid": "newsletter-uuid-1",
    "title": "March Product Update",
    "status": "sent",
    "subject": "What's new in March",
    "preview_text": "Three new things, two hot takes, one demo",
    "email_identity_id": 1,
    "from_email": "hello@example.com",
    "from_name": "Acme Inc",
    "html_content": "<h1>March Update</h1><p>Here is what is new...</p>",
    "text_content": "March Update\n\nHere is what is new...",
    "scheduled_at": "2024-03-01T09:00:00.000000Z",
    "sent_at": "2024-03-01T09:00:00.000000Z",
    "segment_id": null,
    "project_ids": [2],
    "filters": [
      { "connector": "and", "category": "tags", "mode": "any", "values": ["vip"] }
    ],
    "ab_test_enabled": false,
    "total_contacts_reached": 842,
    "metrics": {
      "total": 842,
      "sent": 842,
      "pending": 0,
      "opened": 301,
      "bounced": 4,
      "unsubscribed": 3,
      "complained": 0,
      "open_rate": 35.7,
      "unsubscribe_rate": 0.4
    },
    "created_at": "2024-02-28T10:00:00.000000Z",
    "updated_at": "2024-03-01T09:00:00.000000Z"
  }
}
```

### POST `/api/v1/newsletters`

Create a new newsletter. Defaults to `"draft"` status. Set `status` to `"live"` to send — this requires `subject`, `html_content`, a verified `email_identity_id`, and `scheduled_at`. If `scheduled_at` is in the future the newsletter is stored as `"scheduled"` and queued by the scheduler when due; otherwise it is dispatched immediately with status `"queued"`. `scheduled_at` is **required when `status` is `"live"`** and **prohibited otherwise**. It must be an ISO 8601 datetime in **UTC** and accepts values up to 60 seconds in the past (clock skew tolerance). If `title` is omitted, it defaults to `"Untitled newsletter"`. The `html_content` is automatically converted to plain text for the `text_content` field.

When `status` is `"live"`, the response has `meta.warnings`: a list of compliance warnings, for example content without `{{ unsubscribe_url }}` or an account without a postal address. Warnings never block the send. Show them to the user. For a draft, `meta.warnings` is an empty list.

**Body fields**

| Field | Type | Required | Description |
|-------|------|----------|-------------|
| `title` | string | No | Newsletter title. Defaults to `"Untitled newsletter"`. |
| `subject` | string | required\_if `status=live` | Email subject. May contain `{{ variables }}`. |
| `preview_text` | string | No | Optional inbox preheader (max 255 chars). Shown next to the subject in clients like Gmail and Apple Mail. May contain `{{ variables }}`. |
| `html_content` | string | required\_if `status=live` | HTML body. Validated against the `SafeHtml` rule. |
| `status` | string | No | `"draft"` (default) or `"live"`. |
| `scheduled_at` | string | required\_if `status=live` | ISO 8601 datetime in **UTC**. Required when `status` is `"live"`; prohibited otherwise. Must be at-or-after the current time (60s clock-skew tolerance). |
| `email_identity_id` | integer | required\_if `status=live` | ID of a verified `email_identity` belonging to the client. |
| `email_template_id` | integer | No | ID of an `email_template` belonging to the client. |
| `ab_test_enabled` | boolean | No | When `true`, `subject_b` and `html_content_b` are required. |
| `subject_b` | string | required\_if `ab_test_enabled=true` | Subject for the B variant. |
| `preview_text_b` | string | No | Optional preheader for the B variant (max 255). |
| `html_content_b` | string | required\_if `ab_test_enabled=true` | HTML body for the B variant. |
| `content_variables` | object | No | Map of `{{ variable }}` defaults: `{ "first_name": "Friend" }`. |
| `filters` | array | No | Audience filters. See the Filters reference. Max 20. |
| `project_ids` | array | No | Numeric project IDs to assign the newsletter to (see Projects section). Replaces the full set; `[]` detaches all. |

**Request**

Headers

```json
{
  "Authorization": "Bearer {your-api-token}",
  "Content-Type": "application/json",
  "Accept": "application/json"
}
```

Body (draft)

```json
{
  "title": "March Product Update",
  "subject": "What's new in March",
  "preview_text": "Three new things, two hot takes, one demo",
  "html_content": "<h1>March Update</h1><p>Here is what is new...</p>",
  "status": "draft",
  "filters": [
    { "connector": "and", "category": "tags", "mode": "any", "values": ["vip", "premium"] }
  ]
}
```

Body (live — send immediately)

Pass the current UTC ISO 8601 timestamp as `scheduled_at` to dispatch right away (e.g. `new Date().toISOString()`).

```json
{
  "title": "March Product Update",
  "subject": "What's new in March",
  "html_content": "<h1>March Update</h1><p>Here is what is new...</p>",
  "status": "live",
  "scheduled_at": "2024-03-01T09:00:00Z",
  "email_identity_id": 1,
  "filters": [
    { "connector": "and", "category": "tags", "mode": "any", "values": ["vip", "premium"] }
  ]
}
```

Body (live — schedule for later)

```json
{
  "title": "Black Friday Tease",
  "subject": "Doors open Friday",
  "html_content": "<p>Mark your calendar.</p>",
  "status": "live",
  "scheduled_at": "2026-11-26T08:00:00Z",
  "email_identity_id": 1
}
```

Body (A/B test)

```json
{
  "title": "Subject Line Test",
  "status": "draft",
  "ab_test_enabled": true,
  "subject": "Variant A: Save 20% today",
  "preview_text": "Inbox preheader for variant A",
  "html_content": "<p>Variant A copy</p>",
  "subject_b": "Variant B: Your 20% off ends tonight",
  "preview_text_b": "Inbox preheader for variant B",
  "html_content_b": "<p>Variant B copy</p>"
}
```

Body (with template + variables)

```json
{
  "title": "Welcome Drip",
  "subject": "Welcome, {{ first_name }}!",
  "html_content": "<p>Hi {{ first_name }}, welcome to {{ company }}.</p>",
  "status": "draft",
  "email_template_id": 1,
  "content_variables": {
    "first_name": "Friend",
    "company": "Acme Inc."
  }
}
```

Body (minimal draft — title auto-generated)

```json
{}
```

**Response – 201**

```json
{
  "data": {
    "id": 3,
    "uuid": "newsletter-uuid-3",
    "title": "March Product Update",
    "status": "draft",
    "subject": "What's new in March",
    "email_identity_id": null,
    "from_email": null,
    "from_name": null,
    "html_content": "<h1>March Update</h1><p>Here is what is new...</p>",
    "text_content": "March Update\n\nHere is what is new...",
    "scheduled_at": null,
    "sent_at": null,
    "segment_id": null,
    "filters": [
      { "connector": "and", "category": "tags", "mode": "any", "values": ["vip", "premium"] }
    ],
    "ab_test_enabled": false,
    "total_contacts_reached": 0,
    "metrics": {
      "total": 0, "sent": 0, "pending": 0, "opened": 0,
      "bounced": 0, "unsubscribed": 0, "complained": 0,
      "open_rate": null, "unsubscribe_rate": null
    },
    "created_at": "2024-03-25T11:00:00.000000Z",
    "updated_at": "2024-03-25T11:00:00.000000Z"
  }
}
```

### PATCH `/api/v1/newsletters/{newsletterId}`

Update an existing draft or scheduled newsletter. Queued, ab\_testing, sent, or failed newsletters return **422** with a `status` validation error (not 404). A request with only `title` and `project_ids` changes these labels in any status. A change to a scheduled newsletter must keep a subject and a body, or it returns **422**. Sending `filters` removes a saved segment from the newsletter. All fields are optional but at least one must be provided. When setting `status` to `"live"`, the newsletter must have `subject`, `html_content`, and a verified `email_identity_id` — either provided in this request or already saved on the newsletter. `scheduled_at` is rejected unless `status` is `"live"`. Setting `status` to `"draft"`, or `scheduled_at` to `null` with no `status`, returns a scheduled newsletter to draft and clears `scheduled_at`. If the send started first, a `"draft"` or `"live"` request returns **422** with a `status` validation error and nothing changes. A `"live"` request returns `meta.warnings`, the same as create. Disabling A/B (`ab_test_enabled=false`) deletes the variant-B message.

**Request**

Headers

```json
{
  "Authorization": "Bearer {your-api-token}",
  "Content-Type": "application/json",
  "Accept": "application/json"
}
```

Body (partial update)

```json
{
  "title": "Updated March Product Update"
}
```

Body (full update with filters)

```json
{
  "title": "Updated March Product Update",
  "subject": "Updated: What's new in March",
  "html_content": "<h1>March Update</h1><p>Updated content...</p>",
  "status": "draft",
  "filters": [
    { "connector": "and", "category": "lists", "mode": "all", "values": [1, 5] }
  ]
}
```

**Response – 200**

```json
{
  "data": {
    "id": 3,
    "uuid": "newsletter-uuid-3",
    "title": "Updated March Product Update",
    "status": "draft",
    "subject": "Updated: What's new in March",
    "email_identity_id": 1,
    "from_email": "hello@example.com",
    "from_name": "Acme Inc",
    "html_content": "<h1>March Update</h1><p>Updated content...</p>",
    "text_content": "March Update\n\nUpdated content...",
    "scheduled_at": null,
    "sent_at": null,
    "filters": [
      { "connector": "and", "category": "lists", "mode": "all", "values": [1, 5] }
    ],
    "ab_test_enabled": false,
    "total_contacts_reached": 0,
    "metrics": {
      "total": 0, "sent": 0, "pending": 0, "opened": 0,
      "bounced": 0, "unsubscribed": 0, "complained": 0,
      "open_rate": null, "unsubscribe_rate": null
    },
    "created_at": "2024-03-25T11:00:00.000000Z",
    "updated_at": "2024-03-25T12:00:00.000000Z"
  }
}
```

### DELETE `/api/v1/newsletters/{newsletterId}`

Delete a draft or scheduled newsletter. Queued, ab\_testing, sent, or failed newsletters return **422** with a `status` validation error.

**Request**

Headers

```json
{
  "Authorization": "Bearer {your-api-token}",
  "Accept": "application/json"
}
```

**Response – 204**

No content.

### POST `/api/v1/newsletters/{newsletterId}/test-mail`

Send a test version of the newsletter to the authenticated user's email address. If the newsletter has no sender, the request returns **422** with an `email_identity_id` validation error. If SES does not accept the email, the request returns **422** with a `send` validation error.

**Response – 200**

```json
{ "message": "Test email sent to you@example.com", "sent_to": "you@example.com" }
```

### POST `/api/v1/newsletters/{newsletterId}/ab-winner`

Pick the winning A/B variant after the test phase. Only available while `status` is `"ab_testing"` (other statuses return 422). Marks the newsletter `"sent"` and stores `ab_test_winner_variant`.

**Body**

```json
{ "winner_variant": "a" }
```

`winner_variant` must be `"a"` or `"b"`.

**Response – 200**

Returns the updated newsletter envelope with `status: "sent"` and `ab_test_winner_variant` populated.

### POST `/api/v1/newsletters/contacts/preview`

Returns the number of contacts that would receive a newsletter with the given filters. Useful for validating audience size before going live. Filter validation is identical to the create/update endpoints.

**Body**

```json
{
  "filters": [
    { "connector": "and", "category": "tags", "mode": "any", "values": ["vip"] }
  ]
}
```

**Response – 200**

```json
{ "count": 1284 }
```

### GET `/api/v1/newsletters/{newsletterId}/variables`

Returns the list of `{{ variables }}` detected in the newsletter's subject and HTML body, plus the current `content_variables` defaults map. Reserved names (`content`, `unsubscribe_url`, `preferences_url`, `opt_in_url`) are excluded automatically.

**Response – 200**

```json
{
  "data": {
    "newsletter_id": 12,
    "detected_variables": ["company", "first_name"],
    "content_variables": {
      "company": "",
      "first_name": "Friend"
    }
  }
}
```

The `content_variables` map also includes saved defaults for variables that the content does not use yet.

### PATCH `/api/v1/newsletters/{newsletterId}/variables`

Replace the content variable defaults of a draft or scheduled newsletter. The `content_variables` object replaces all saved defaults. Send an empty object to clear them. Each default is text of up to 255 characters, the same rule as the dashboard. A default can name a variable that the content does not use yet. Each variable that the content uses and the object leaves out gets an empty default.

**Request body:**

```json
{
  "content_variables": {
    "company": "Acme",
    "first_name": "Friend"
  }
}
```

**Response:** the same shape as `GET /api/v1/newsletters/{newsletterId}/variables`.

**Errors:** `422` when `content_variables` is missing, when a default is longer than 255 characters, or when the newsletter is sending or sent. `404` when the newsletter does not belong to your account.

### Link clicks

The link endpoints report who clicked which link in a newsletter or a sequence step.

- **Named links:** add a `data-bm-link` attribute to an `<a>` tag, for example `<a href="https://example.com/sale" data-bm-link="black-friday">`. A named link groups all clicks under its link name, also when the URL changes.
- BYOMailer changes a link name to lowercase letters, digits and dashes. For example, "Black Friday" becomes `black-friday`. The maximum length is 100 characters.
- Only emails that you send after you give the link a name carry the link name.
- **Unnamed links** group by URL. The URL has no `utm_*` parameters and no `#fragment`.
- Each link has a **link key**: `name:<link name>` or `url:<url>`. Use the link key to list the contacts who clicked that link.
- `click_rate` is the unique contacts divided by the delivered mails, times 100. If no mail is delivered, `click_rate` is `null`.
- **Bot clicks:** BYOMailer does not filter bot clicks yet. The clicks can include link scanners, for example Microsoft Safe Links and Mimecast.

### GET `/api/v1/newsletters/{newsletterId}/links`

List the links of a newsletter with their clicks. The links with the most unique contacts come first.

**Response – 200**

```json
{
  "data": {
    "delivered": 3,
    "links": [
      {
        "key": "name:black-friday",
        "name": "black-friday",
        "url": "https://example.com/sale",
        "unique_contacts": 2,
        "total_clicks": 3,
        "click_rate": 66.67
      },
      {
        "key": "url:https://example.com/blog",
        "name": null,
        "url": "https://example.com/blog",
        "unique_contacts": 1,
        "total_clicks": 1,
        "click_rate": 33.33
      }
    ]
  }
}
```

**Errors:** `404` when the newsletter does not belong to your account.

### GET `/api/v1/newsletters/{newsletterId}/links/contacts`

List the contacts who clicked one link of a newsletter, cursor-paginated.

Query Parameters

| Parameter | Type   | Description |
|-----------|--------|-------------|
| link      | string | Required. A link key from the link list, for example `name:black-friday` or `url:https://example.com/blog`. |
| per_page  | int    | Results per page (default 50, max 200). |
| cursor    | string | Cursor for the next/previous page (see Pagination section). |

**Example:** `GET /api/v1/newsletters/12/links/contacts?link=name:black-friday&per_page=50`

**Response – 200**

```json
{
  "data": [
    {
      "contact_mail_id": 9081,
      "contact_id": 42,
      "email": "john@example.com",
      "name": "John Doe",
      "clicks": 2,
      "first_clicked_at": "2026-10-27T14:05:12+00:00"
    }
  ],
  "links": {
    "first": null,
    "last": null,
    "prev": null,
    "next": "https://byomailer.example/api/v1/newsletters/12/links/contacts?link=name%3Ablack-friday&per_page=50&cursor=eyJpZCI6OTA4MSwiX3BvaW50c1RvTmV4dEl0ZW1zIjp0cnVlfQ"
  },
  "meta": {
    "path": "https://byomailer.example/api/v1/newsletters/12/links/contacts",
    "per_page": 50,
    "next_cursor": "eyJpZCI6OTA4MSwiX3BvaW50c1RvTmV4dEl0ZW1zIjp0cnVlfQ",
    "prev_cursor": null
  }
}
```

**Errors:** `422` when `link` is missing, or when `link` does not start with `name:` or `url:` ("The link must be a key from the link list."). `404` when the newsletter does not belong to your account.

## Sequences

Sequences are automated, multi-step email flows. These endpoints are read-only — sequences are built and managed through the dashboard. They mirror the BYOMailer MCP server's sequence tools, so the same data is available to both REST and AI clients.

### GET `/api/v1/sequences`

List sequences for the authenticated account, cursor-paginated. Live sequences are returned first, then alphabetically by name.

Query parameters: `status` (`draft`, `live`, `paused`. `paused` is the same as `draft`: a paused sequence has the status `draft`), `search` (partial name match), `project` (numeric project ID — only sequences assigned to that project), `per_page` (default 25, max 100), `cursor`.

```json
{
  "data": [
    {
      "id": 7,
      "name": "Onboarding",
      "status": "live",
      "steps_count": 4,
      "contacts_count": 318
    }
  ],
  "meta": {
    "path": "https://byomailer.example/api/v1/sequences",
    "per_page": 25,
    "next_cursor": "eyJpZCI6N30",
    "prev_cursor": null
  }
}
```

### GET `/api/v1/sequences/{sequenceId}`

Get a single sequence with the full content of every step.

```json
{
  "data": {
    "id": 7,
    "name": "Onboarding",
    "status": "live",
    "contacts_count": 318,
    "steps_count": 4,
    "steps": [
      {
        "id": 31,
        "sequence_id": 7,
        "delay_days": 0,
        "delay_type": "days",
        "status": "live",
        "filters": [],
        "message": {
          "id": 88,
          "subject": "Welcome aboard",
          "preview_text": "Glad you are here",
          "html_content": "<p>Hi {{ name }}…</p>",
          "text_content": "Hi {{ name }}…",
          "email_identity_id": 3
        },
        "created_at": "2026-06-01T09:00:00.000000Z"
      }
    ],
    "filters": [],
    "content_variables": { "name": "there" },
    "created_at": "2026-06-01T09:00:00.000000Z"
  }
}
```

### GET `/api/v1/sequences/{sequenceId}/stats`

Get sequence-level delivery and engagement stats: contact, unsubscribe and click totals plus aggregate mail metrics across all steps. For a per-step breakdown call the step stats endpoint below.

```json
{
  "data": {
    "id": 7,
    "name": "Onboarding",
    "status": "live",
    "stats": { "contacts": 318, "unsubscribed": 6, "clicked": 121 },
    "mail_metrics": {
      "total": 1180,
      "sent": 1180,
      "delivered": 1170,
      "pending": 0,
      "opened": 540,
      "bounced": 10,
      "unsubscribed": 6,
      "complained": 0,
      "delivery_rate": 99.2,
      "open_rate": 45.8,
      "unsubscribe_rate": 0.5
    }
  }
}
```

### GET `/api/v1/sequences/{sequenceId}/variables`

Get the `{{ variables }}` that the subjects and bodies of the steps use (`detected_variables`), and the saved default of each one (`content_variables`). A default fills a variable when the contact has no value for it. Reserved variables (`content`, `unsubscribe_url`, `preferences_url`, `opt_in_url`) are not included.

```json
{
  "data": {
    "sequence_id": 7,
    "detected_variables": ["checkout_url", "first_name"],
    "content_variables": { "checkout_url": "https://example.com/checkout", "first_name": "" }
  }
}
```

### PATCH `/api/v1/sequences/{sequenceId}/variables`

Replace the content variable defaults of the sequence. The `content_variables` object replaces all saved defaults. Send an empty object to clear them. Each default is text of up to 255 characters, the same rule as the dashboard. A default can name a variable that no step uses yet. This endpoint works on draft and live sequences.

**Request body:**

```json
{
  "content_variables": {
    "checkout_url": "https://example.com/checkout",
    "first_name": "there"
  }
}
```

**Response:** the same shape as `GET /api/v1/sequences/{sequenceId}/variables`.

**Errors:** `422` when `content_variables` is missing or a default is longer than 255 characters. `404` when the sequence does not belong to your account.

### GET `/api/v1/sequences/{sequenceId}/steps/{sequenceStepId}`

Get the full content of a single sequence step.

```json
{
  "data": {
    "id": 31,
    "sequence_id": 7,
    "delay_days": 0,
    "delay_type": "days",
    "status": "live",
    "filters": [],
    "message": {
      "id": 88,
      "subject": "Welcome aboard",
      "preview_text": "Glad you are here",
      "html_content": "<p>Hi {{ name }}…</p>",
      "text_content": "Hi {{ name }}…",
      "email_identity": { "id": 3, "name": "Acme", "email": "hello@acme.com" }
    },
    "created_at": "2026-06-01T09:00:00.000000Z"
  }
}
```

### PATCH `/api/v1/sequences/{sequenceId}/steps/{sequenceStepId}/filters`

Replace the filters of a single sequence step. A contact who does not match the step filters skips only this email and continues to the next step. The sequence filters still apply first. This endpoint works on draft and live steps. Send an empty `filters` array to clear the filters. The filter structure and validation are the same as for newsletters (maximum 20 filters). The response is the updated step.

**Request body:**

```json
{
  "filters": [
    { "connector": "and", "category": "tags", "mode": "any", "values": ["vip"] }
  ]
}
```

**Response:** the step, in the same shape as `GET /api/v1/sequences/{sequenceId}/steps/{sequenceStepId}`, with the saved `filters`.

**Errors:** `422` when a filter is not valid (for example, an unknown category or a tag that does not exist). `404` when the step does not belong to the sequence or to your account.

### GET `/api/v1/sequences/{sequenceId}/steps/{sequenceStepId}/stats`

Get delivery and engagement stats for a single sequence step. Contacts are counted in the furthest step they have reached, so the per-step totals do not double-count contacts who have progressed.

```json
{
  "data": {
    "id": 31,
    "sequence_id": 7,
    "subject": "Welcome aboard",
    "delay_days": 0,
    "delay_type": "days",
    "status": "live",
    "stats": { "contacts": 318, "unsubscribed": 2, "clicked": 80 },
    "mail_metrics": {
      "total": 318,
      "sent": 318,
      "delivered": 316,
      "pending": 0,
      "opened": 190,
      "bounced": 2,
      "unsubscribed": 2,
      "complained": 0,
      "delivery_rate": 99.4,
      "open_rate": 59.7,
      "unsubscribe_rate": 0.6
    }
  }
}
```

### GET `/api/v1/sequences/{sequenceId}/steps/{sequenceStepId}/links`

List the links of a sequence step with their clicks. The response has the same shape as `GET /api/v1/newsletters/{newsletterId}/links`. For named links, link keys and bot clicks, see [Link clicks](#link-clicks).

**Response – 200**

```json
{
  "data": {
    "delivered": 316,
    "links": [
      {
        "key": "name:pricing",
        "name": "pricing",
        "url": "https://example.com/pricing",
        "unique_contacts": 64,
        "total_clicks": 81,
        "click_rate": 20.25
      }
    ]
  }
}
```

**Errors:** `404` when the step does not belong to the sequence or to your account.

### GET `/api/v1/sequences/{sequenceId}/steps/{sequenceStepId}/links/contacts`

List the contacts who clicked one link of a sequence step, cursor-paginated. The query parameters and the response have the same shape as `GET /api/v1/newsletters/{newsletterId}/links/contacts`.

Query Parameters

| Parameter | Type   | Description |
|-----------|--------|-------------|
| link      | string | Required. A link key from the link list, for example `name:pricing`. |
| per_page  | int    | Results per page (default 50, max 200). |
| cursor    | string | Cursor for the next/previous page (see Pagination section). |

**Example:** `GET /api/v1/sequences/7/steps/31/links/contacts?link=name:pricing`

**Response – 200**

```json
{
  "data": [
    {
      "contact_mail_id": 5520,
      "contact_id": 42,
      "email": "john@example.com",
      "name": "John Doe",
      "clicks": 1,
      "first_clicked_at": "2026-10-27T09:12:40+00:00"
    }
  ],
  "links": {
    "first": null,
    "last": null,
    "prev": null,
    "next": null
  },
  "meta": {
    "path": "https://byomailer.example/api/v1/sequences/7/steps/31/links/contacts",
    "per_page": 50,
    "next_cursor": null,
    "prev_cursor": null
  }
}
```

**Errors:** `422` when `link` is missing, or when `link` is not a link key. `404` when the step does not belong to the sequence or to your account.

## Projects

Projects are named groupings that organize newsletters, sequences, and automations. Assign resources to projects via `project_ids` on newsletter create/update, then filter list endpoints with `?project=<projectId>`.

### GET `/api/v1/projects`

Return all projects for the authenticated client. Cursor-paginated (`per_page` default 25, max 100; `cursor` for next/previous page). Each project includes counts of attached resources.

**Request**

Headers

```json
{
  "Authorization": "Bearer {your-api-token}",
  "Accept": "application/json"
}
```

**Response – 200**

```json
{
  "data": [
    {
      "id": 2,
      "uuid": "project-uuid-2",
      "name": "Q3 Launch",
      "newsletters_count": 4,
      "sequences_count": 1,
      "automations_count": 0,
      "visual_automations_count": 2,
      "created_at": "2026-07-20T10:00:00.000000Z"
    },
    {
      "id": 1,
      "uuid": "project-uuid-1",
      "name": "Evergreen Onboarding",
      "newsletters_count": 0,
      "sequences_count": 3,
      "automations_count": 1,
      "visual_automations_count": 0,
      "created_at": "2026-07-18T09:00:00.000000Z"
    }
  ],
  "meta": {
    "path": "https://byomailer.example/api/v1/projects",
    "per_page": 25,
    "next_cursor": null,
    "prev_cursor": null
  }
}
```

### GET `/api/v1/projects/{projectId}`

Return a single project by ID, including attached resource counts.

**Request**

Headers

```json
{
  "Authorization": "Bearer {your-api-token}",
  "Accept": "application/json"
}
```

**Response – 200**

```json
{
  "data": {
    "id": 2,
    "uuid": "project-uuid-2",
    "name": "Q3 Launch",
    "newsletters_count": 4,
    "sequences_count": 1,
    "automations_count": 0,
    "visual_automations_count": 2,
    "created_at": "2026-07-20T10:00:00.000000Z"
  }
}
```

### POST `/api/v1/projects`

Create a new project. The `name` must be unique per account (case-insensitive).

**Request**

Headers

```json
{
  "Authorization": "Bearer {your-api-token}",
  "Content-Type": "application/json",
  "Accept": "application/json"
}
```

Body

```json
{
  "name": "Q3 Launch"
}
```

**Response – 201**

```json
{
  "data": {
    "id": 3,
    "uuid": "project-uuid-3",
    "name": "Q3 Launch",
    "created_at": "2026-07-23T11:00:00.000000Z"
  }
}
```

**Validation Error – 422**

```json
{
  "message": "A project with this name already exists.",
  "errors": {
    "name": [
      "A project with this name already exists."
    ]
  }
}
```

### PATCH `/api/v1/projects/{projectId}`

Rename a project. The `name` must be unique per account (case-insensitive).

**Request**

Headers

```json
{
  "Authorization": "Bearer {your-api-token}",
  "Content-Type": "application/json",
  "Accept": "application/json"
}
```

Body

```json
{
  "name": "Q3 Product Launch"
}
```

**Response – 200**

```json
{
  "data": {
    "id": 3,
    "uuid": "project-uuid-3",
    "name": "Q3 Product Launch",
    "created_at": "2026-07-23T11:00:00.000000Z"
  }
}
```

### DELETE `/api/v1/projects/{projectId}`

Delete a project. Attached newsletters, sequences, and automations are detached, not deleted.

**Request**

Headers

```json
{
  "Authorization": "Bearer {your-api-token}",
  "Accept": "application/json"
}
```

**Response – 204**

```json
{}
```

## Email Identities

Email identities are verified sender addresses used to send emails through Amazon SES. These endpoints are read-only — identities are managed through the dashboard.

### GET `/api/v1/email-identities`

Return all email identities for the authenticated user.

**Request**

Headers

```json
{
  "Authorization": "Bearer {your-api-token}",
  "Accept": "application/json"
}
```

**Response – 200**

```json
{
  "data": [
    {
      "id": 1,
      "uuid": "identity-uuid-1",
      "email": "hello@example.com",
      "name": "Acme Inc",
      "domain": "example.com",
      "identity_status": "verified",
      "verified_at": "2024-01-15T10:30:00.000000Z",
      "created_at": "2024-01-10T08:00:00.000000Z",
      "updated_at": "2024-01-15T10:30:00.000000Z"
    }
  ]
}
```

### GET `/api/v1/email-identities/{emailIdentityId}`

Return a single email identity by ID.

**Response – 200**

```json
{
  "data": {
    "id": 1,
    "uuid": "identity-uuid-1",
    "email": "hello@example.com",
    "name": "Acme Inc",
    "domain": "example.com",
    "identity_status": "verified",
    "verified_at": "2024-01-15T10:30:00.000000Z",
    "created_at": "2024-01-10T08:00:00.000000Z",
    "updated_at": "2024-01-15T10:30:00.000000Z"
  }
}
```

## Email Templates

Email templates are reusable HTML wrappers. Newsletters can reference a template via `email_template_id` so the body slot is rendered inside it.

### GET `/api/v1/email-templates`

Return email templates for the authenticated client. Active templates by default; pass `?archived=true` for archived ones.

**Response – 200**

```json
{
  "data": [
    {
      "id": 1,
      "name": "Default wrapper",
      "html_content": "<table>...{{ content }}...</table>",
      "is_default": true,
      "is_system": false,
      "archived_at": null,
      "created_at": "2024-02-01T08:00:00.000000Z",
      "updated_at": "2024-02-01T08:00:00.000000Z"
    }
  ],
  "meta": {
    "path": "https://byomailer.example/api/v1/email-templates",
    "per_page": 25,
    "next_cursor": null,
    "prev_cursor": null
  }
}
```

### GET `/api/v1/email-templates/default`

Return the active default email template for the authenticated client. When no default is set, the response is `{"data": null}`.

### GET `/api/v1/email-templates/{emailTemplateId}`

Return a single template by ID.

## Topics

Topics are content categories inside a list. Subscribers are auto-subscribed to every topic on the lists they belong to and can opt out of individual topics from their preference center.

### GET `/api/v1/lists/{listId}/topics`

Return all topics for the given list.

**Request**

```json
{
  "Authorization": "Bearer {your-api-token}",
  "Accept": "application/json"
}
```

**Response – 200**

```json
{
  "data": [
    {
      "id": 1,
      "uuid": "topic-uuid-1",
      "list_id": 10,
      "name": "Product Updates",
      "description": "Release notes and feature announcements",
      "created_at": "2026-04-15T10:30:00.000000Z"
    }
  ]
}
```

### POST `/api/v1/lists/{listId}/topics`

Create a topic on a list. All existing contacts on the list are auto-subscribed to the new topic asynchronously via a background job.

**Request body**

```json
{
  "name": "Product Updates",
  "description": "Release notes and feature announcements"
}
```

**Response – 201**

```json
{
  "data": {
    "id": 2,
    "uuid": "topic-uuid-2",
    "list_id": 10,
    "name": "Product Updates",
    "description": "Release notes and feature announcements",
    "created_at": "2026-04-15T10:35:00.000000Z"
  }
}
```

### PATCH `/api/v1/lists/{listId}/topics/{topicId}`

Update a topic.

**Request body**

```json
{
  "name": "Product News",
  "description": null
}
```

**Response – 200**

```json
{
  "data": {
    "id": 2,
    "uuid": "topic-uuid-2",
    "list_id": 10,
    "name": "Product News",
    "description": null,
    "created_at": "2026-04-15T10:35:00.000000Z"
  }
}
```

### DELETE `/api/v1/lists/{listId}/topics/{topicId}`

Delete a topic and all subscription state associated with it.

When a newsletter that is not sent, a sequence, a sequence step, a segment or a visual automation uses the topic, the request fails with `422`. The error names each item. Remove the topic from these items first.

**Response – 204**

```json
{}
```

**Response – 422**

```json
{
  "message": "These items use the topic: newsletter \"Spring sale\" (#4). Remove the topic from them, then delete the topic.",
  "errors": {
    "topic": [
      "These items use the topic: newsletter \"Spring sale\" (#4). Remove the topic from them, then delete the topic."
    ]
  }
}
```

## Blocks

Blocks are reusable HTML content snippets that can be embedded in emails using the `{{ block.name }}` template syntax. The `html_content` field is validated for safe HTML — dangerous elements like `<script>`, `<iframe>`, event handlers (`onclick`, `onerror`, etc.), and `javascript:` URLs are rejected.

### GET `/api/v1/blocks`

Return all blocks owned by the authenticated user.

**Request**

Headers

```json
{
  "Authorization": "Bearer {your-api-token}",
  "Accept": "application/json"
}
```

**Response – 200**

```json
{
  "data": [
    {
      "id": 1,
      "uuid": "block-uuid-1",
      "name": "footer_links",
      "html_content": "<div><a href=\"https://example.com\">Home</a></div>",
      "created_at": "2024-01-15T10:30:00.000000Z",
      "updated_at": "2024-01-15T10:30:00.000000Z"
    },
    {
      "id": 2,
      "uuid": "block-uuid-2",
      "name": "welcome_header",
      "html_content": "<h1>Welcome!</h1>",
      "created_at": "2024-01-14T09:00:00.000000Z",
      "updated_at": "2024-01-14T09:00:00.000000Z"
    }
  ]
}
```

### GET `/api/v1/blocks/{blockId}`

Return a single block by ID.

**Request**

Headers

```json
{
  "Authorization": "Bearer {your-api-token}",
  "Accept": "application/json"
}
```

**Response – 200**

```json
{
  "data": {
    "id": 1,
    "uuid": "block-uuid-1",
    "name": "footer_links",
    "html_content": "<div><a href=\"https://example.com\">Home</a></div>",
    "created_at": "2024-01-15T10:30:00.000000Z",
    "updated_at": "2024-01-15T10:30:00.000000Z"
  }
}
```

### POST `/api/v1/blocks`

Create a new block. The `name` must contain only lowercase letters, numbers, and underscores. It must be unique per account.

**Request**

Headers

```json
{
  "Authorization": "Bearer {your-api-token}",
  "Content-Type": "application/json",
  "Accept": "application/json"
}
```

Body

```json
{
  "name": "footer_links",
  "html_content": "<div><a href=\"https://example.com\">Home</a></div>"
}
```

**Response – 201**

```json
{
  "data": {
    "id": 3,
    "uuid": "block-uuid-3",
    "name": "footer_links",
    "html_content": "<div><a href=\"https://example.com\">Home</a></div>",
    "created_at": "2024-01-16T11:00:00.000000Z",
    "updated_at": "2024-01-16T11:00:00.000000Z"
  }
}
```

**Validation Error – 422**

```json
{
  "message": "The html_content contains unsafe HTML. The <script> tag is not allowed.",
  "errors": {
    "html_content": [
      "The html_content contains unsafe HTML. The <script> tag is not allowed."
    ]
  }
}
```

### PATCH `/api/v1/blocks/{blockId}`

Update an existing block.

**Request**

Headers

```json
{
  "Authorization": "Bearer {your-api-token}",
  "Content-Type": "application/json",
  "Accept": "application/json"
}
```

Body

```json
{
  "name": "updated_footer",
  "html_content": "<div><a href=\"https://example.com\">Updated Home</a></div>"
}
```

**Response – 200**

```json
{
  "data": {
    "id": 3,
    "uuid": "block-uuid-3",
    "name": "updated_footer",
    "html_content": "<div><a href=\"https://example.com\">Updated Home</a></div>",
    "created_at": "2024-01-16T11:00:00.000000Z",
    "updated_at": "2024-01-16T12:00:00.000000Z"
  }
}
```

### DELETE `/api/v1/blocks/{blockId}`

Delete a block.

**Request**

Headers

```json
{
  "Authorization": "Bearer {your-api-token}",
  "Accept": "application/json"
}
```

**Response – 204**

```json
{}
```

## Email Logs

### GET `/api/v1/email-logs`

Retrieve a paginated list of email logs. Supports optional query parameters for filtering.

**Request**

Headers

```json
{
  "Authorization": "Bearer {your-api-token}",
  "Accept": "application/json"
}
```

Query Parameters

| Parameter   | Type   | Description                                      |
|-------------|--------|--------------------------------------------------|
| status      | string | Filter by status (sent, failed, delivered, etc.)  |
| operation   | string | Filter by SES operation name                      |
| recipient   | string | Search by recipient email (partial match)         |
| per_page    | int    | Results per page (default 25, max 100)            |
| cursor      | string | Cursor for the next/previous page (see Pagination section). |

**Response – 200**

```json
{
  "data": [
    {
      "id": 1,
      "provider": "ses",
      "operation": "SendBulkEmail",
      "recipient": "john@example.com",
      "message_id": "0102018e-abcd-1234-5678-abcdef123456",
      "status": "sent",
      "error": null,
      "request_payload": null,
      "response_payload": { "Status": "SUCCESS", "MessageId": "0102018e-abcd-1234-5678-abcdef123456" },
      "sns_payload": null,
      "logged_at": "2024-01-15T10:30:00.000000Z",
      "created_at": "2024-01-15T10:30:00.000000Z"
    }
  ],
  "meta": {
    "path": "https://byomailer.example/api/v1/email-logs",
    "per_page": 25,
    "next_cursor": "eyJpZCI6MTAxLCJfcG9pbnRzVG9OZXh0SXRlbXMiOnRydWV9",
    "prev_cursor": null
  }
}
```

### GET `/api/v1/email-logs/{emailLogId}`

Retrieve a single email log entry by ID.

**Request**

Headers

```json
{
  "Authorization": "Bearer {your-api-token}",
  "Accept": "application/json"
}
```

**Response – 200**

```json
{
  "data": {
    "id": 1,
    "provider": "ses",
    "operation": "SendBulkEmail",
    "recipient": "john@example.com",
    "message_id": "0102018e-abcd-1234-5678-abcdef123456",
    "status": "delivered",
    "error": null,
    "request_payload": null,
    "response_payload": { "Status": "SUCCESS", "MessageId": "0102018e-abcd-1234-5678-abcdef123456" },
    "sns_payload": { "eventType": "Delivery", "mail": { "messageId": "0102018e-abcd-1234-5678-abcdef123456" } },
    "logged_at": "2024-01-15T10:30:00.000000Z",
    "created_at": "2024-01-15T10:30:00.000000Z"
  }
}
```

---

## Webhooks

Register HTTPS endpoints that receive signed `POST` callbacks when events happen in your account. **Admin-only.** All endpoints are under `/api/v1/webhook-endpoints`.

Supported event types: `contact.subscribed`, `contact.unsubscribed`, `contact.resubscribed`, `newsletter.sent` (once per newsletter), `sequence_step.sent`, `sequence.completed`.

### Delivery headers

Each delivery POST carries:

- `X-Byomailer-Event` — the event type
- `X-Byomailer-Delivery` — a unique delivery UUID
- `X-Byomailer-Timestamp` — Unix seconds when the delivery was signed
- `X-Byomailer-Signature` — `sha256=<hex>` HMAC of the body
- `User-Agent: BYOMailer-Webhook/1.0`

### Verifying signatures

Compute `HMAC-SHA256(timestamp + "." + rawBody, secret)` with your endpoint's signing secret and compare it to the `X-Byomailer-Signature` value using a timing-safe comparison. Reject deliveries whose `X-Byomailer-Timestamp` is outside a ±5 minute window to prevent replay.

```php
$expected = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);
$valid = hash_equals($expected, $request->header('X-Byomailer-Signature'));
```

Failed deliveries are retried with exponential backoff (5 attempts). After 15 consecutive failures an endpoint is automatically disabled (`disabled_reason: auto_failures`).

### Create Webhook Endpoint

`POST /api/v1/webhook-endpoints`

The plaintext signing `secret` is returned **once** in this response. URL must be a public HTTPS endpoint (private/reserved IPs are rejected).

```json
{
  "url": "https://example.com/webhooks/byomailer",
  "events": ["contact.subscribed", "newsletter.sent"],
  "description": "CRM sync"
}
```

Response `201`:

```json
{
  "data": {
    "id": 1,
    "uuid": "wh-uuid-1",
    "url": "https://example.com/webhooks/byomailer",
    "events": ["contact.subscribed", "newsletter.sent"],
    "active": true,
    "secret": "whsec_xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
  }
}
```

### Example delivery payload (`contact.subscribed`)

```json
{
  "event": "contact.subscribed",
  "occurred_at": "2026-01-15T10:30:00+00:00",
  "contact": { "company": "Acme Inc", "id": 1, "uuid": "contact-uuid-1", "email": "john@example.com", "name": "John Doe" }
}
```

### Other operations

- `GET /api/v1/webhook-endpoints` — list (secret never returned)
- `GET /api/v1/webhook-endpoints/{id}` — show
- `PATCH /api/v1/webhook-endpoints/{id}` — update `active` / `description` only (URL and events are immutable; recreate to change)
- `DELETE /api/v1/webhook-endpoints/{id}` — delete (`204`)
- `GET /api/v1/webhook-endpoints/{id}/deliveries` — cursor-paginated delivery log
- `POST /api/v1/webhook-endpoints/{id}/test` — queue a test delivery (`202`, rate-limited)
- `POST /api/v1/webhook-endpoints/{id}/secret` — rotate the secret (returned once)
