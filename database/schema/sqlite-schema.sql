BEGIN TRANSACTION;
CREATE TABLE IF NOT EXISTS "migrations"(
  "id" integer primary key autoincrement not null,
  "migration" varchar not null,
  "batch" integer not null
);
CREATE TABLE IF NOT EXISTS "password_reset_tokens"(
  "email" varchar not null,
  "token" varchar not null,
  "created_at" datetime,
  primary key("email")
);
CREATE TABLE IF NOT EXISTS "sessions"(
  "id" varchar not null,
  "user_id" integer,
  "ip_address" varchar,
  "user_agent" text,
  "payload" text not null,
  "last_activity" integer not null,
  primary key("id")
);
CREATE INDEX "sessions_user_id_index" on "sessions"("user_id");
CREATE INDEX "sessions_last_activity_index" on "sessions"("last_activity");
CREATE TABLE IF NOT EXISTS "cache"(
  "key" varchar not null,
  "value" text not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE TABLE IF NOT EXISTS "cache_locks"(
  "key" varchar not null,
  "owner" varchar not null,
  "expiration" integer not null,
  primary key("key")
);
CREATE TABLE IF NOT EXISTS "jobs"(
  "id" integer primary key autoincrement not null,
  "queue" varchar not null,
  "payload" text not null,
  "attempts" integer not null,
  "reserved_at" integer,
  "available_at" integer not null,
  "created_at" integer not null
);
CREATE INDEX "jobs_queue_index" on "jobs"("queue");
CREATE TABLE IF NOT EXISTS "job_batches"(
  "id" varchar not null,
  "name" varchar not null,
  "total_jobs" integer not null,
  "pending_jobs" integer not null,
  "failed_jobs" integer not null,
  "failed_job_ids" text not null,
  "options" text,
  "cancelled_at" integer,
  "created_at" integer not null,
  "finished_at" integer,
  primary key("id")
);
CREATE TABLE IF NOT EXISTS "failed_jobs"(
  "id" integer primary key autoincrement not null,
  "uuid" varchar not null,
  "connection" text not null,
  "queue" text not null,
  "payload" text not null,
  "exception" text not null,
  "failed_at" datetime not null default CURRENT_TIMESTAMP
);
CREATE UNIQUE INDEX "failed_jobs_uuid_unique" on "failed_jobs"("uuid");
CREATE TABLE IF NOT EXISTS "contact_tag"(
  "id" integer primary key autoincrement not null,
  "contact_id" integer not null,
  "tag_id" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("contact_id") references "contacts"("id") on delete cascade,
  foreign key("tag_id") references "tags"("id") on delete cascade
);
CREATE UNIQUE INDEX "contact_tag_contact_id_tag_id_unique" on "contact_tag"(
  "contact_id",
  "tag_id"
);
CREATE TABLE IF NOT EXISTS "contact_list"(
  "id" integer primary key autoincrement not null,
  "contact_id" integer not null,
  "list_id" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  "opted_in_at" datetime,
  foreign key("contact_id") references "contacts"("id") on delete cascade,
  foreign key("list_id") references "lists"("id") on delete cascade
);
CREATE UNIQUE INDEX "contact_list_contact_id_list_id_unique" on "contact_list"(
  "contact_id",
  "list_id"
);
CREATE TABLE IF NOT EXISTS "personal_access_tokens"(
  "id" integer primary key autoincrement not null,
  "tokenable_type" varchar not null,
  "tokenable_id" integer not null,
  "name" text not null,
  "token" varchar not null,
  "abilities" text,
  "last_used_at" datetime,
  "expires_at" datetime,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "personal_access_tokens_tokenable_type_tokenable_id_index" on "personal_access_tokens"(
  "tokenable_type",
  "tokenable_id"
);
CREATE UNIQUE INDEX "personal_access_tokens_token_unique" on "personal_access_tokens"(
  "token"
);
CREATE INDEX "personal_access_tokens_expires_at_index" on "personal_access_tokens"(
  "expires_at"
);
CREATE TABLE IF NOT EXISTS "sequence_steps"(
  "id" integer primary key autoincrement not null,
  "sequence_id" integer not null,
  "delay_days" integer not null default '0',
  "status" varchar not null default 'draft',
  "created_at" datetime,
  "updated_at" datetime,
  "delay_type" varchar not null default 'days',
  "filters" text,
  foreign key("sequence_id") references "sequences"("id") on delete cascade
);
CREATE TABLE IF NOT EXISTS "contact_sequence"(
  "id" integer primary key autoincrement not null,
  "contact_id" integer not null,
  "sequence_id" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  "completed_at" datetime,
  foreign key("contact_id") references "contacts"("id") on delete cascade,
  foreign key("sequence_id") references "sequences"("id") on delete cascade
);
CREATE UNIQUE INDEX "contact_sequence_contact_id_sequence_id_unique" on "contact_sequence"(
  "contact_id",
  "sequence_id"
);
CREATE TABLE IF NOT EXISTS "email_identity_dns_records"(
  "id" integer primary key autoincrement not null,
  "email_identity_id" integer not null,
  "type" varchar not null,
  "name" varchar not null,
  "value" text not null,
  "priority" integer,
  "verified_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "purpose" varchar,
  foreign key("email_identity_id") references "email_identities"("id") on delete cascade
);
CREATE TABLE IF NOT EXISTS "contact_mail_clicks"(
  "id" integer primary key autoincrement not null,
  "contact_mail_id" integer not null,
  "contact_id" integer not null,
  "link" text,
  "clicked_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("contact_mail_id") references "contact_mails"("id") on delete cascade,
  foreign key("contact_id") references "contacts"("id") on delete cascade
);
CREATE TABLE IF NOT EXISTS "media"(
  "id" integer primary key autoincrement not null,
  "model_type" varchar not null,
  "model_id" integer not null,
  "uuid" varchar,
  "collection_name" varchar not null,
  "name" varchar not null,
  "file_name" varchar not null,
  "mime_type" varchar,
  "disk" varchar not null,
  "conversions_disk" varchar,
  "size" integer not null,
  "manipulations" text not null,
  "custom_properties" text not null,
  "generated_conversions" text not null,
  "responsive_images" text not null,
  "order_column" integer,
  "created_at" datetime,
  "updated_at" datetime
);
CREATE INDEX "media_model_type_model_id_index" on "media"(
  "model_type",
  "model_id"
);
CREATE UNIQUE INDEX "media_uuid_unique" on "media"("uuid");
CREATE INDEX "media_order_column_index" on "media"("order_column");
CREATE TABLE IF NOT EXISTS "clients"(
  "id" integer primary key autoincrement not null,
  "uuid" varchar not null,
  "name" varchar not null,
  "slug" varchar not null,
  "email" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "role" varchar not null default 'customer',
  "timezone" varchar not null default 'UTC',
  "sunset_inactive_days" integer,
  "postal_address" text
);
CREATE UNIQUE INDEX "clients_uuid_unique" on "clients"("uuid");
CREATE UNIQUE INDEX "clients_slug_unique" on "clients"("slug");
CREATE TABLE IF NOT EXISTS "users"(
  "id" integer primary key autoincrement not null,
  "name" varchar not null,
  "email" varchar not null,
  "email_verified_at" datetime,
  "password" varchar not null,
  "remember_token" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "two_factor_secret" text,
  "two_factor_recovery_codes" text,
  "two_factor_confirmed_at" datetime,
  "role" varchar not null default('customer'),
  "client_id" integer not null,
  "uuid" varchar,
  "invite_expires_at" datetime,
  "archived_at" datetime,
  "is_owner" tinyint(1) not null default '0',
  "deactivated_at" datetime,
  foreign key("client_id") references "clients"("id") on delete cascade
);
CREATE UNIQUE INDEX "users_email_unique" on "users"("email");
CREATE INDEX "users_role_index" on "users"("role");
CREATE UNIQUE INDEX "users_uuid_unique" on "users"("uuid");
CREATE TABLE IF NOT EXISTS "visual_automations"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "client_id" integer not null,
  "name" varchar not null,
  "status" varchar not null default 'draft',
  "workflow" text not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("client_id") references "clients"("id") on delete cascade
);
CREATE TABLE IF NOT EXISTS "visual_automation_enrollments"(
  "id" integer primary key autoincrement not null,
  "visual_automation_id" integer not null,
  "contact_id" integer not null,
  "status" varchar not null default 'active',
  "enrolled_at" datetime not null default CURRENT_TIMESTAMP,
  "completed_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("visual_automation_id") references "visual_automations"("id") on delete cascade,
  foreign key("contact_id") references "contacts"("id") on delete cascade
);
CREATE INDEX "visual_automation_enrollments_status_index" on "visual_automation_enrollments"(
  "status"
);
CREATE UNIQUE INDEX "va_enrollments_automation_contact_unique" on "visual_automation_enrollments"(
  "visual_automation_id",
  "contact_id"
);
CREATE TABLE IF NOT EXISTS "visual_automation_contact_steps"(
  "id" integer primary key autoincrement not null,
  "enrollment_id" integer not null,
  "node_id" varchar not null,
  "status" varchar not null default 'pending',
  "wait_until" datetime,
  "processed_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("enrollment_id") references "visual_automation_enrollments"("id") on delete cascade
);
CREATE INDEX "visual_automation_contact_steps_enrollment_id_status_index" on "visual_automation_contact_steps"(
  "enrollment_id",
  "status"
);
CREATE UNIQUE INDEX "va_contact_steps_enrollment_node_unique" on "visual_automation_contact_steps"(
  "enrollment_id",
  "node_id"
);
CREATE INDEX "visual_automation_contact_steps_node_id_index" on "visual_automation_contact_steps"(
  "node_id"
);
CREATE TABLE IF NOT EXISTS "blocks"(
  "id" integer primary key autoincrement not null,
  "client_id" integer not null,
  "user_id" integer not null,
  "uuid" varchar not null,
  "name" varchar not null,
  "html_content" text not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("client_id") references "clients"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE UNIQUE INDEX "blocks_client_id_name_unique" on "blocks"(
  "client_id",
  "name"
);
CREATE UNIQUE INDEX "blocks_uuid_unique" on "blocks"("uuid");
CREATE TABLE IF NOT EXISTS "email_templates"(
  "id" integer primary key autoincrement not null,
  "client_id" integer,
  "user_id" integer,
  "name" varchar not null,
  "html_content" text not null,
  "created_at" datetime,
  "updated_at" datetime,
  "is_default" tinyint(1) not null default '0',
  "archived_at" datetime,
  foreign key("client_id") references "clients"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete set null
);
CREATE TABLE IF NOT EXISTS "email_logs"(
  "id" integer primary key autoincrement not null,
  "client_id" integer not null,
  "provider" varchar not null default 'ses',
  "operation" varchar not null,
  "recipient" varchar,
  "message_id" varchar,
  "status" varchar not null,
  "error" text,
  "request_payload" text,
  "response_payload" text,
  "sns_payload" text,
  "logged_at" datetime not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("client_id") references "clients"("id") on delete cascade
);
CREATE INDEX "email_logs_client_id_logged_at_index" on "email_logs"(
  "client_id",
  "logged_at"
);
CREATE INDEX "email_logs_provider_index" on "email_logs"("provider");
CREATE INDEX "email_logs_message_id_index" on "email_logs"("message_id");
CREATE INDEX "email_logs_status_index" on "email_logs"("status");
CREATE TABLE IF NOT EXISTS "topics"(
  "id" integer primary key autoincrement not null,
  "client_id" integer not null,
  "user_id" integer not null,
  "list_id" integer not null,
  "uuid" varchar not null,
  "name" varchar not null,
  "description" text,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("client_id") references "clients"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete cascade,
  foreign key("list_id") references "lists"("id") on delete cascade
);
CREATE UNIQUE INDEX "topics_list_id_name_unique" on "topics"(
  "list_id",
  "name"
);
CREATE INDEX "topics_client_id_index" on "topics"("client_id");
CREATE UNIQUE INDEX "topics_uuid_unique" on "topics"("uuid");
CREATE TABLE IF NOT EXISTS "contact_topic"(
  "id" integer primary key autoincrement not null,
  "contact_id" integer not null,
  "topic_id" integer not null,
  "subscribed_at" datetime,
  "unsubscribed_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("contact_id") references "contacts"("id") on delete cascade,
  foreign key("topic_id") references "topics"("id") on delete cascade
);
CREATE UNIQUE INDEX "contact_topic_contact_id_topic_id_unique" on "contact_topic"(
  "contact_id",
  "topic_id"
);
CREATE INDEX "contact_topic_topic_id_unsubscribed_at_index" on "contact_topic"(
  "topic_id",
  "unsubscribed_at"
);
CREATE TABLE IF NOT EXISTS "test_mails"(
  "id" integer primary key autoincrement not null,
  "client_id" integer not null,
  "user_id" integer not null,
  "uuid" varchar not null,
  "mailable_type" varchar not null,
  "mailable_id" integer not null,
  "sent_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("client_id") references "clients"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete cascade
);
CREATE INDEX "test_mails_mailable_type_mailable_id_index" on "test_mails"(
  "mailable_type",
  "mailable_id"
);
CREATE UNIQUE INDEX "test_mails_uuid_unique" on "test_mails"("uuid");
CREATE INDEX "email_templates_client_id_is_default_index" on "email_templates"(
  "client_id",
  "is_default"
);
CREATE INDEX "email_templates_archived_at_index" on "email_templates"(
  "archived_at"
);
CREATE INDEX "contact_sequence_completed_at_index" on "contact_sequence"(
  "completed_at"
);
CREATE TABLE IF NOT EXISTS "webhook_endpoints"(
  "id" integer primary key autoincrement not null,
  "client_id" integer not null,
  "user_id" integer,
  "uuid" varchar not null,
  "url" varchar not null,
  "secret" text not null,
  "description" varchar,
  "events" text not null,
  "active" tinyint(1) not null default '1',
  "disabled_reason" varchar,
  "consecutive_failures" integer not null default '0',
  "last_triggered_at" datetime,
  "disabled_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("client_id") references "clients"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete set null
);
CREATE INDEX "webhook_endpoints_client_id_active_index" on "webhook_endpoints"(
  "client_id",
  "active"
);
CREATE UNIQUE INDEX "webhook_endpoints_uuid_unique" on "webhook_endpoints"(
  "uuid"
);
CREATE TABLE IF NOT EXISTS "webhook_deliveries"(
  "id" integer primary key autoincrement not null,
  "client_id" integer not null,
  "webhook_endpoint_id" integer not null,
  "uuid" varchar not null,
  "event_type" varchar not null,
  "payload" text not null,
  "status" varchar not null default 'pending',
  "attempts" integer not null default '0',
  "response_status" integer,
  "response_body" text,
  "error" text,
  "dispatched_at" datetime,
  "delivered_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("client_id") references "clients"("id") on delete cascade,
  foreign key("webhook_endpoint_id") references "webhook_endpoints"("id") on delete cascade
);
CREATE INDEX "webhook_deliveries_webhook_endpoint_id_id_index" on "webhook_deliveries"(
  "webhook_endpoint_id",
  "id"
);
CREATE INDEX "webhook_deliveries_client_id_created_at_index" on "webhook_deliveries"(
  "client_id",
  "created_at"
);
CREATE UNIQUE INDEX "webhook_deliveries_uuid_unique" on "webhook_deliveries"(
  "uuid"
);
CREATE TABLE IF NOT EXISTS "segments"(
  "id" integer primary key autoincrement not null,
  "client_id" integer not null,
  "name" varchar not null,
  "description" text,
  "filters" text not null,
  "archived_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("client_id") references "clients"("id") on delete cascade
);
CREATE UNIQUE INDEX "segments_client_id_name_unique" on "segments"(
  "client_id",
  "name"
);
CREATE TABLE IF NOT EXISTS "projects"(
  "id" integer primary key autoincrement not null,
  "client_id" integer not null,
  "user_id" integer,
  "uuid" varchar not null,
  "name" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("client_id") references "clients"("id") on delete cascade,
  foreign key("user_id") references "users"("id") on delete set null
);
CREATE UNIQUE INDEX "projects_client_id_name_unique" on "projects"(
  "client_id",
  "name"
);
CREATE UNIQUE INDEX "projects_uuid_unique" on "projects"("uuid");
CREATE TABLE IF NOT EXISTS "projectables"(
  "id" integer primary key autoincrement not null,
  "project_id" integer not null,
  "projectable_type" varchar not null,
  "projectable_id" integer not null,
  "created_at" datetime,
  "updated_at" datetime,
  foreign key("project_id") references "projects"("id") on delete cascade
);
CREATE INDEX "projectables_projectable_type_projectable_id_index" on "projectables"(
  "projectable_type",
  "projectable_id"
);
CREATE UNIQUE INDEX "projectables_project_id_projectable_id_projectable_type_unique" on "projectables"(
  "project_id",
  "projectable_id",
  "projectable_type"
);
CREATE TABLE IF NOT EXISTS "instance_settings"(
  "key" varchar not null,
  "value" text,
  "created_at" datetime,
  "updated_at" datetime,
  primary key("key")
);
CREATE TABLE IF NOT EXISTS "automations"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "name" varchar not null,
  "type" varchar not null,
  "condition" text not null,
  "actions" text not null,
  "created_at" datetime,
  "updated_at" datetime,
  "is_live" tinyint(1) not null default('1'),
  "client_id" integer not null,
  foreign key("user_id") references "users"("id") on delete cascade on update no action,
  foreign key("client_id") references "clients"("id") on delete cascade
);
CREATE TABLE IF NOT EXISTS "blacklisted_emails"(
  "id" integer primary key autoincrement not null,
  "user_id" integer,
  "email" varchar not null,
  "reason" varchar,
  "created_at" datetime,
  "updated_at" datetime,
  "client_id" integer,
  foreign key("client_id") references "clients"("id") on delete cascade
);
CREATE UNIQUE INDEX "blacklisted_emails_client_id_email_unique" on "blacklisted_emails"(
  "client_id",
  "email"
);
CREATE INDEX "blacklisted_emails_email_index" on "blacklisted_emails"("email");
CREATE TABLE IF NOT EXISTS "contact_attributes"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "uuid" varchar not null,
  "name" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  "client_id" integer not null,
  "default_value" varchar,
  foreign key("user_id") references "users"("id") on delete cascade on update no action,
  foreign key("client_id") references "clients"("id") on delete cascade
);
CREATE UNIQUE INDEX "contact_attributes_client_id_name_unique" on "contact_attributes"(
  "client_id",
  "name"
);
CREATE INDEX "contact_attributes_user_id_index" on "contact_attributes"(
  "user_id"
);
CREATE UNIQUE INDEX "contact_attributes_uuid_unique" on "contact_attributes"(
  "uuid"
);
CREATE TABLE IF NOT EXISTS "contact_imports"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "list_id" integer not null,
  "batch_id" varchar,
  "status" varchar not null default('pending'),
  "original_filename" varchar not null,
  "total_count" integer not null default('0'),
  "processed_count" integer not null default('0'),
  "skipped_count" integer not null default('0'),
  "error_message" text,
  "created_at" datetime,
  "updated_at" datetime,
  "client_id" integer not null,
  foreign key("user_id") references "users"("id") on delete cascade on update no action,
  foreign key("list_id") references "lists"("id") on delete cascade on update no action,
  foreign key("client_id") references "clients"("id") on delete cascade
);
CREATE TABLE IF NOT EXISTS "contact_mails"(
  "id" integer primary key autoincrement not null,
  "uuid" varchar not null,
  "contact_id" integer not null,
  "mailable_type" varchar,
  "mailable_id" integer,
  "subject" varchar,
  "from_email" varchar,
  "from_name" varchar,
  "template_data" text,
  "scheduled_at" datetime,
  "sent_at" datetime,
  "delivered_at" datetime,
  "opened_at" datetime,
  "bounced_at" datetime,
  "rejected_at" datetime,
  "complained_at" datetime,
  "unsubscribed_at" datetime,
  "failure_reason" text,
  "status" varchar not null default('pending'),
  "created_at" datetime,
  "updated_at" datetime,
  "clicked_at" datetime,
  "message_id" integer,
  "client_id" integer not null,
  "open_ip_address" varchar,
  "open_user_agent" text,
  "open_timezone" varchar,
  "open_country" varchar,
  "open_city" varchar,
  "reserved_at" datetime,
  foreign key("contact_id") references "contacts"("id") on delete cascade on update no action,
  foreign key("message_id") references "messages"("id") on delete set null on update no action,
  foreign key("client_id") references "clients"("id") on delete cascade
);
CREATE UNIQUE INDEX "contact_mailable_unique" on "contact_mails"(
  "contact_id",
  "mailable_type",
  "mailable_id"
);
CREATE INDEX "contact_mails_client_mailable_index" on "contact_mails"(
  "client_id",
  "mailable_type",
  "mailable_id"
);
CREATE INDEX "contact_mails_client_sent_at_index" on "contact_mails"(
  "client_id",
  "sent_at",
  "bounced_at",
  "complained_at"
);
CREATE INDEX "contact_mails_mailable_type_mailable_id_index" on "contact_mails"(
  "mailable_type",
  "mailable_id"
);
CREATE INDEX "contact_mails_sequence_due_index" on "contact_mails"(
  "mailable_type",
  "status",
  "scheduled_at"
);
CREATE UNIQUE INDEX "contact_mails_uuid_unique" on "contact_mails"("uuid");
CREATE TABLE IF NOT EXISTS "contacts"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "uuid" varchar not null,
  "name" varchar,
  "email" varchar not null,
  "extra_attributes" text,
  "unsubscribed_at" datetime,
  "unsubscribed_by_contact" tinyint(1) not null default('0'),
  "created_at" datetime,
  "updated_at" datetime,
  "client_id" integer not null,
  "timezone" varchar,
  "country" varchar,
  "soft_bounce_count" integer not null default('0'),
  "last_engaged_at" datetime,
  "sunset_at" datetime,
  foreign key("user_id") references "users"("id") on delete cascade on update no action,
  foreign key("client_id") references "clients"("id") on delete cascade
);
CREATE UNIQUE INDEX "contacts_client_id_email_unique" on "contacts"(
  "client_id",
  "email"
);
CREATE INDEX "contacts_client_sunset_index" on "contacts"(
  "client_id",
  "sunset_at",
  "last_engaged_at",
  "created_at"
);
CREATE INDEX "contacts_country_index" on "contacts"("country");
CREATE INDEX "contacts_timezone_index" on "contacts"("timezone");
CREATE INDEX "contacts_user_id_index" on "contacts"("user_id");
CREATE UNIQUE INDEX "contacts_uuid_unique" on "contacts"("uuid");
CREATE TABLE IF NOT EXISTS "email_credentials"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "type" varchar not null default('ses'),
  "access_key" text not null,
  "secret_key" text not null,
  "region" varchar not null default('us-east-1'),
  "configuration_set_name" varchar,
  "configuration_set_created_at" datetime,
  "sns_topic_arn" varchar,
  "sns_subscription_arn" varchar,
  "sns_subscription_confirmed_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "production_access_enabled" tinyint(1) not null default('0'),
  "sending_enabled" tinyint(1) not null default('1'),
  "max_24_hour_send" integer not null default('200'),
  "max_send_rate" integer not null default('1'),
  "sent_last_24_hours" integer not null default('0'),
  "quota_fetched_at" datetime,
  "client_id" integer not null,
  "open_tracking_enabled" tinyint(1) not null default('1'),
  "click_tracking_enabled" tinyint(1) not null default('1'),
  "custom_tracking_domain" varchar,
  "custom_tracking_domain_verified_at" datetime,
  "can_create_template" tinyint(1) not null default('0'),
  "can_delete_template" tinyint(1) not null default('0'),
  "can_send_bulk_email" tinyint(1) not null default('0'),
  "can_send_email" tinyint(1) not null default('0'),
  "permissions_checked_at" datetime,
  "permissions_alert_count" integer not null default('0'),
  "permissions_alerted_at" datetime,
  "tenant_name" varchar,
  "aws_account_id" varchar,
  "enforcement_status" varchar,
  foreign key("user_id") references "users"("id") on delete cascade on update no action,
  foreign key("client_id") references "clients"("id") on delete cascade
);
CREATE UNIQUE INDEX "email_credentials_client_id_type_unique" on "email_credentials"(
  "client_id",
  "type"
);
CREATE INDEX "email_credentials_user_id_index" on "email_credentials"(
  "user_id"
);
CREATE TABLE IF NOT EXISTS "email_identities"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "uuid" varchar not null,
  "email" varchar not null,
  "original_email" varchar,
  "name" varchar,
  "domain" varchar,
  "mail_from_subdomain" varchar,
  "region" varchar,
  "identity_status" varchar not null default('pending'),
  "last_status_check_at" datetime,
  "verified_at" datetime,
  "created_at" datetime,
  "updated_at" datetime,
  "client_id" integer not null,
  "configuration_set_name" varchar,
  "configuration_set_created_at" datetime,
  "open_tracking_enabled" tinyint(1) not null default('1'),
  "click_tracking_enabled" tinyint(1) not null default('1'),
  "custom_tracking_domain" varchar,
  "custom_tracking_domain_verified_at" datetime,
  "tenant_associated_at" datetime,
  "custom_link_domain" varchar,
  "custom_link_domain_verified_at" datetime,
  "cf_custom_hostname_id" varchar,
  "reply_to_email" varchar,
  "reply_to_name" varchar,
  foreign key("user_id") references "users"("id") on delete cascade on update no action,
  foreign key("client_id") references "clients"("id") on delete cascade
);
CREATE UNIQUE INDEX "email_identities_client_id_email_unique" on "email_identities"(
  "client_id",
  "email"
);
CREATE INDEX "email_identities_custom_link_domain_index" on "email_identities"(
  "custom_link_domain"
);
CREATE INDEX "email_identities_tracking_domain_verified_idx" on "email_identities"(
  "custom_tracking_domain",
  "custom_tracking_domain_verified_at"
);
CREATE INDEX "email_identities_user_id_index" on "email_identities"("user_id");
CREATE UNIQUE INDEX "email_identities_uuid_unique" on "email_identities"(
  "uuid"
);
CREATE TABLE IF NOT EXISTS "lists"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "uuid" varchar not null,
  "name" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  "client_id" integer not null,
  "opt_in_enabled" tinyint(1) not null default('0'),
  foreign key("user_id") references "users"("id") on delete cascade on update no action,
  foreign key("client_id") references "clients"("id") on delete cascade
);
CREATE UNIQUE INDEX "lists_client_id_name_unique" on "lists"(
  "client_id",
  "name"
);
CREATE INDEX "lists_user_id_index" on "lists"("user_id");
CREATE UNIQUE INDEX "lists_uuid_unique" on "lists"("uuid");
CREATE TABLE IF NOT EXISTS "messages"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "messageable_type" varchar not null,
  "messageable_id" integer not null,
  "email_identity_id" integer,
  "subject" varchar,
  "from_email" varchar,
  "from_name" varchar,
  "html_content" text,
  "text_content" text,
  "markdown_content" text,
  "created_at" datetime,
  "updated_at" datetime,
  "variant" varchar,
  "client_id" integer not null,
  "email_template_id" integer,
  "template_slot_content" text,
  "preview_text" varchar,
  foreign key("user_id") references "users"("id") on delete cascade on update no action,
  foreign key("email_identity_id") references "email_identities"("id") on delete set null on update no action,
  foreign key("email_template_id") references "email_templates"("id") on delete set null on update no action,
  foreign key("client_id") references "clients"("id") on delete cascade
);
CREATE INDEX "messages_messageable_type_messageable_id_index" on "messages"(
  "messageable_type",
  "messageable_id"
);
CREATE TABLE IF NOT EXISTS "newsletters"(
  "id" integer primary key autoincrement not null,
  "uuid" varchar not null,
  "user_id" integer not null,
  "scheduled_at" datetime,
  "sent_at" datetime,
  "status" varchar not null default('draft'),
  "filters" text,
  "created_at" datetime,
  "updated_at" datetime,
  "template_name" varchar,
  "title" varchar not null default(''),
  "content_variables" text,
  "ab_test_enabled" tinyint(1) not null default('0'),
  "ab_template_name" varchar,
  "ab_test_winner_variant" varchar,
  "client_id" integer not null,
  "total_contacts_reached" integer not null default('0'),
  "segment_id" integer,
  "last_dispatched_contact_id" integer,
  foreign key("segment_id") references "segments"("id") on delete set null on update no action,
  foreign key("user_id") references "users"("id") on delete cascade on update no action,
  foreign key("client_id") references "clients"("id") on delete cascade
);
CREATE UNIQUE INDEX "newsletters_uuid_unique" on "newsletters"("uuid");
CREATE TABLE IF NOT EXISTS "sequences"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "name" varchar not null,
  "status" varchar not null default('draft'),
  "created_at" datetime,
  "updated_at" datetime,
  "filters" text,
  "content_variables" text,
  "client_id" integer not null,
  "segment_id" integer,
  foreign key("segment_id") references "segments"("id") on delete set null on update no action,
  foreign key("user_id") references "users"("id") on delete cascade on update no action,
  foreign key("client_id") references "clients"("id") on delete cascade
);
CREATE TABLE IF NOT EXISTS "tags"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "uuid" varchar not null,
  "name" varchar not null,
  "created_at" datetime,
  "updated_at" datetime,
  "client_id" integer not null,
  foreign key("user_id") references "users"("id") on delete cascade on update no action,
  foreign key("client_id") references "clients"("id") on delete cascade
);
CREATE UNIQUE INDEX "tags_client_id_name_unique" on "tags"(
  "client_id",
  "name"
);
CREATE INDEX "tags_user_id_index" on "tags"("user_id");
CREATE UNIQUE INDEX "tags_uuid_unique" on "tags"("uuid");
CREATE TABLE IF NOT EXISTS "user_storage_settings"(
  "id" integer primary key autoincrement not null,
  "user_id" integer not null,
  "provider" varchar not null,
  "bucket" varchar not null,
  "region" varchar not null,
  "endpoint" varchar,
  "access_key_id" text not null,
  "secret_access_key" text not null,
  "public_base_url" varchar not null,
  "path_prefix" varchar not null default('byomailer/{user_id}'),
  "is_verified" tinyint(1) not null default('0'),
  "created_at" datetime,
  "updated_at" datetime,
  "client_id" integer not null,
  foreign key("user_id") references "users"("id") on delete cascade on update no action,
  foreign key("client_id") references "clients"("id") on delete cascade
);
CREATE UNIQUE INDEX "user_storage_settings_user_id_unique" on "user_storage_settings"(
  "user_id"
);

INSERT INTO migrations VALUES(1,'0001_01_01_000000_create_users_table',1);
INSERT INTO migrations VALUES(2,'0001_01_01_000001_create_cache_table',1);
INSERT INTO migrations VALUES(3,'0001_01_01_000002_create_jobs_table',1);
INSERT INTO migrations VALUES(4,'2025_08_14_170933_add_two_factor_columns_to_users_table',1);
INSERT INTO migrations VALUES(5,'2026_01_20_180128_create_contacts_table',1);
INSERT INTO migrations VALUES(6,'2026_01_20_180138_create_lists_table',1);
INSERT INTO migrations VALUES(7,'2026_01_20_180141_create_tags_table',1);
INSERT INTO migrations VALUES(8,'2026_01_20_180144_create_newsletters_table',1);
INSERT INTO migrations VALUES(9,'2026_01_20_180147_create_contact_tag_table',1);
INSERT INTO migrations VALUES(10,'2026_01_20_180150_create_contact_list_table',1);
INSERT INTO migrations VALUES(11,'2026_01_20_182434_create_personal_access_tokens_table',1);
INSERT INTO migrations VALUES(12,'2026_01_20_184810_create_contact_attributes_table',1);
INSERT INTO migrations VALUES(13,'2026_01_20_191321_create_email_identities_table',1);
INSERT INTO migrations VALUES(14,'2026_01_20_212640_create_contact_mails_table',1);
INSERT INTO migrations VALUES(15,'2026_01_20_220119_create_automations_table',1);
INSERT INTO migrations VALUES(16,'2026_01_20_224638_create_blacklisted_emails_table',1);
INSERT INTO migrations VALUES(17,'2026_01_20_231258_create_messages_table',1);
INSERT INTO migrations VALUES(18,'2026_01_21_171853_create_sequences_table',1);
INSERT INTO migrations VALUES(19,'2026_01_21_171856_create_sequence_steps_table',1);
INSERT INTO migrations VALUES(20,'2026_01_21_181405_create_contact_sequence_table',1);
INSERT INTO migrations VALUES(21,'2026_01_24_231221_create_contact_imports_table',1);
INSERT INTO migrations VALUES(22,'2026_01_26_204949_create_email_credentials_table',1);
INSERT INTO migrations VALUES(23,'2026_01_28_212953_create_email_identity_dns_records_table',1);
INSERT INTO migrations VALUES(24,'2026_01_30_181432_name_column_can_be_null_in_contacts_table',1);
INSERT INTO migrations VALUES(25,'2026_01_31_164241_add_template_name_to_newsletters_table',1);
INSERT INTO migrations VALUES(26,'2026_02_01_201617_add_ses_quota_fields_to_email_credentials_table',1);
INSERT INTO migrations VALUES(27,'2026_02_02_214159_remove_list_id_from_newsletters_table',1);
INSERT INTO migrations VALUES(28,'2026_02_04_144201_create_subscriptions_table',1);
INSERT INTO migrations VALUES(29,'2026_02_04_144202_create_subscription_items_table',1);
INSERT INTO migrations VALUES(30,'2026_02_04_144203_add_meter_id_to_subscription_items_table',1);
INSERT INTO migrations VALUES(31,'2026_02_04_144204_add_meter_event_name_to_subscription_items_table',1);
INSERT INTO migrations VALUES(32,'2026_02_04_144214_create_customer_columns',1);
INSERT INTO migrations VALUES(33,'2026_02_05_150331_add_role_column_to_users_table',1);
INSERT INTO migrations VALUES(34,'2026_02_09_174314_add_is_live_to_automations_table',1);
INSERT INTO migrations VALUES(35,'2026_02_09_230454_add_filters_to_sequences_table',1);
INSERT INTO migrations VALUES(36,'2026_02_13_192412_add_delay_type_to_sequence_steps_table',1);
INSERT INTO migrations VALUES(37,'2026_02_13_202647_add_clicked_at_to_contact_mails_table',1);
INSERT INTO migrations VALUES(38,'2026_02_13_213309_create_contact_mail_clicks_table',1);
INSERT INTO migrations VALUES(39,'2026_02_20_200917_add_title_to_newsletters_table',1);
INSERT INTO migrations VALUES(40,'2026_02_21_174521_create_media_table',1);
INSERT INTO migrations VALUES(41,'2026_02_23_165159_add_content_variables_to_newsletters_table',1);
INSERT INTO migrations VALUES(42,'2026_02_23_165159_add_content_variables_to_sequences_table',1);
INSERT INTO migrations VALUES(43,'2026_02_24_223536_create_user_storage_settings_table',1);
INSERT INTO migrations VALUES(44,'2026_02_25_224055_add_ab_test_columns_to_newsletters_table',1);
INSERT INTO migrations VALUES(45,'2026_02_25_224055_add_message_id_to_contact_mails_table',1);
INSERT INTO migrations VALUES(46,'2026_02_25_224055_add_variant_to_messages_table',1);
INSERT INTO migrations VALUES(47,'2026_02_27_000001_create_clients_table',1);
INSERT INTO migrations VALUES(48,'2026_02_27_000002_introduce_clients',1);
INSERT INTO migrations VALUES(49,'2026_03_01_000000_add_total_contacts_reached_to_newsletters_table',1);
INSERT INTO migrations VALUES(50,'2026_03_03_144617_add_opt_in_columns_to_lists_table',1);
INSERT INTO migrations VALUES(51,'2026_03_03_144621_add_opted_in_at_to_contact_list_table',1);
INSERT INTO migrations VALUES(52,'2026_03_03_144625_backfill_opted_in_at_on_contact_list_table',1);
INSERT INTO migrations VALUES(53,'2026_03_04_230252_add_client_id_column_to_subscriptions_table',1);
INSERT INTO migrations VALUES(54,'2026_03_04_231408_add_purpose_to_email_identity_dns_records_table',1);
INSERT INTO migrations VALUES(55,'2026_03_05_213136_add_uuid_and_invite_fields_to_users_table',1);
INSERT INTO migrations VALUES(56,'2026_03_05_221000_add_archived_at_to_users_table',1);
INSERT INTO migrations VALUES(57,'2026_03_10_232651_create_visual_automations_table',1);
INSERT INTO migrations VALUES(58,'2026_03_10_232654_create_visual_automation_enrollments_table',1);
INSERT INTO migrations VALUES(59,'2026_03_10_232657_create_visual_automation_contact_steps_table',1);
INSERT INTO migrations VALUES(60,'2026_03_13_183358_add_role_to_clients_table',1);
INSERT INTO migrations VALUES(61,'2026_03_15_042158_add_tracking_settings_to_email_credentials_table',1);
INSERT INTO migrations VALUES(62,'2026_03_16_234840_add_custom_tracking_domain_to_email_credentials_table',1);
INSERT INTO migrations VALUES(63,'2026_03_21_200653_create_blocks_table',1);
INSERT INTO migrations VALUES(64,'2026_03_22_000000_add_ses_permissions_to_email_credentials_table',1);
INSERT INTO migrations VALUES(65,'2026_03_24_205240_add_permission_notification_tracking_to_email_credentials_table',1);
INSERT INTO migrations VALUES(66,'2026_03_25_193813_create_email_templates_table',1);
INSERT INTO migrations VALUES(67,'2026_03_26_025708_add_open_geo_tracking_to_contact_mails_table',1);
INSERT INTO migrations VALUES(68,'2026_03_26_025708_add_timezone_to_contacts_table',1);
INSERT INTO migrations VALUES(69,'2026_03_31_163344_add_configuration_set_to_email_identities_table',1);
INSERT INTO migrations VALUES(70,'2026_04_06_143629_move_timezone_from_users_to_clients',1);
INSERT INTO migrations VALUES(71,'2026_04_06_151843_drop_timezone_from_users_table',1);
INSERT INTO migrations VALUES(72,'2026_04_07_154632_create_email_logs_table',1);
INSERT INTO migrations VALUES(73,'2026_04_08_000001_add_tenant_columns_to_email_credentials_table',1);
INSERT INTO migrations VALUES(74,'2026_04_08_000002_add_tenant_associated_at_to_email_identities_table',1);
INSERT INTO migrations VALUES(75,'2026_04_08_184854_add_client_id_index_to_contact_mails_table',1);
INSERT INTO migrations VALUES(76,'2026_04_15_120001_create_topics_table',1);
INSERT INTO migrations VALUES(77,'2026_04_15_120002_create_contact_topic_table',1);
INSERT INTO migrations VALUES(78,'2026_04_16_053523_create_test_mails_table',1);
INSERT INTO migrations VALUES(79,'2026_04_23_100000_add_is_default_to_email_templates_table',1);
INSERT INTO migrations VALUES(80,'2026_04_23_100001_add_template_fields_to_messages_table',1);
INSERT INTO migrations VALUES(82,'2026_04_23_100003_backfill_messages_with_default_template',1);
INSERT INTO migrations VALUES(83,'2026_04_24_155834_make_messages_subject_nullable',1);
INSERT INTO migrations VALUES(84,'2026_05_16_204741_add_custom_link_domain_to_email_identities_table',1);
INSERT INTO migrations VALUES(85,'2026_05_21_120000_add_index_to_custom_tracking_domain',1);
INSERT INTO migrations VALUES(86,'2026_05_22_111351_add_preview_text_to_messages_table',1);
INSERT INTO migrations VALUES(87,'2026_06_25_000001_add_completed_at_to_contact_sequence_table',1);
INSERT INTO migrations VALUES(88,'2026_06_25_000002_create_webhook_endpoints_table',1);
INSERT INTO migrations VALUES(89,'2026_06_25_000003_create_webhook_deliveries_table',1);
INSERT INTO migrations VALUES(90,'2026_06_25_100000_create_segments_table',1);
INSERT INTO migrations VALUES(91,'2026_07_01_154754_add_client_mailable_index_to_contact_mails_table',1);
INSERT INTO migrations VALUES(92,'2026_07_21_000001_add_default_value_to_contact_attributes_table',1);
INSERT INTO migrations VALUES(93,'2026_07_21_210926_create_projects_table',1);
INSERT INTO migrations VALUES(94,'2026_07_23_143554_create_projectables_table',1);
INSERT INTO migrations VALUES(95,'2026_09_19_000001_add_email_index_to_blacklisted_emails_table',1);
INSERT INTO migrations VALUES(96,'2026_09_22_000001_add_soft_bounce_count_to_contacts_table',1);
INSERT INTO migrations VALUES(97,'2026_09_22_000001_add_sunset_inactive_days_to_clients_table',1);
INSERT INTO migrations VALUES(98,'2026_09_22_000002_add_engagement_columns_to_contacts_table',1);
INSERT INTO migrations VALUES(99,'2026_09_22_000003_backfill_contacts_last_engaged_at',1);
INSERT INTO migrations VALUES(100,'2026_09_22_000004_add_sunset_index_to_contacts_table',1);
INSERT INTO migrations VALUES(101,'2026_09_22_233133_add_enforcement_status_to_email_credentials_table',1);
INSERT INTO migrations VALUES(102,'2026_09_22_233627_add_reserved_at_to_contact_mails_table',1);
INSERT INTO migrations VALUES(103,'2026_09_22_233628_add_sequence_due_index_to_contact_mails_table',1);
INSERT INTO migrations VALUES(104,'2026_09_22_233714_add_reply_to_to_email_identities_table',1);
INSERT INTO migrations VALUES(105,'2026_09_22_234136_add_client_sent_at_index_to_contact_mails_table',1);
INSERT INTO migrations VALUES(106,'2026_09_22_234137_drop_client_id_index_from_contact_mails_table',1);
INSERT INTO migrations VALUES(107,'2026_09_23_153418_add_filters_to_sequence_steps_table',1);
INSERT INTO migrations VALUES(108,'2026_11_16_000001_drop_billing_tables_and_columns',1);
INSERT INTO migrations VALUES(109,'2026_11_16_000002_add_postal_address_to_clients_table',1);
INSERT INTO migrations VALUES(110,'2026_11_17_000001_add_is_owner_to_users_table',1);
INSERT INTO migrations VALUES(111,'2026_11_17_000002_create_instance_settings_table',1);
INSERT INTO migrations VALUES(112,'2026_11_17_000003_add_deactivated_at_to_users_table',1);
INSERT INTO migrations VALUES(113,'2026_11_18_000001_add_client_foreign_keys',1);
INSERT INTO migrations VALUES(114,'2026_11_19_171019_add_last_dispatched_contact_id_to_newsletters_table',1);
COMMIT;
