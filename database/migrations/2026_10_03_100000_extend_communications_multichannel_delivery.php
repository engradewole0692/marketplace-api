<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('bulk_email_jobs')) {
            Schema::table('bulk_email_jobs', function (Blueprint $table): void {
                if (! Schema::hasColumn('bulk_email_jobs', 'channels')) {
                    $table->json('channels')->nullable()->after('recipient_filters');
                }
                if (! Schema::hasColumn('bulk_email_jobs', 'module')) {
                    $table->string('module', 60)->nullable()->after('channels');
                }
                if (! Schema::hasColumn('bulk_email_jobs', 'message_type')) {
                    $table->string('message_type', 20)->default('promotional')->after('module');
                }
                if (! Schema::hasColumn('bulk_email_jobs', 'whatsapp_template')) {
                    $table->json('whatsapp_template')->nullable()->after('message_type');
                }
                if (! Schema::hasColumn('bulk_email_jobs', 'preview_snapshot')) {
                    $table->json('preview_snapshot')->nullable()->after('whatsapp_template');
                }
                if (! Schema::hasColumn('bulk_email_jobs', 'accepted_count')) {
                    $table->unsignedInteger('accepted_count')->default(0)->after('sent_count');
                }
                if (! Schema::hasColumn('bulk_email_jobs', 'delivered_count')) {
                    $table->unsignedInteger('delivered_count')->default(0)->after('accepted_count');
                }
                if (! Schema::hasColumn('bulk_email_jobs', 'skipped_count')) {
                    $table->unsignedInteger('skipped_count')->default(0)->after('failed_count');
                }
                if (! Schema::hasColumn('bulk_email_jobs', 'cancelled_at')) {
                    $table->timestamp('cancelled_at')->nullable()->after('completed_at');
                }
                if (! Schema::hasColumn('bulk_email_jobs', 'cancelled_by')) {
                    $table->unsignedBigInteger('cancelled_by')->nullable()->after('cancelled_at');
                }
                if (! Schema::hasColumn('bulk_email_jobs', 'last_error')) {
                    $table->string('last_error', 500)->nullable()->after('cancelled_by');
                }
            });
        }

        if (Schema::hasTable('bulk_email_recipients')) {
            Schema::table('bulk_email_recipients', function (Blueprint $table): void {
                if (! Schema::hasColumn('bulk_email_recipients', 'channel')) {
                    $table->string('channel', 20)->default('email')->after('bulk_email_job_id');
                }
                if (! Schema::hasColumn('bulk_email_recipients', 'destination')) {
                    $table->string('destination', 191)->nullable()->after('email');
                }
                if (! Schema::hasColumn('bulk_email_recipients', 'person_id')) {
                    $table->unsignedBigInteger('person_id')->nullable()->after('user_id');
                }
                if (! Schema::hasColumn('bulk_email_recipients', 'idempotency_key')) {
                    $table->string('idempotency_key', 191)->nullable()->unique('bulk_email_recipients_idempotency_unique');
                }
                if (! Schema::hasColumn('bulk_email_recipients', 'provider')) {
                    $table->string('provider', 40)->nullable();
                }
                if (! Schema::hasColumn('bulk_email_recipients', 'provider_message_id')) {
                    $table->string('provider_message_id', 191)->nullable()->index('bulk_email_recipients_provider_msg_idx');
                }
                if (! Schema::hasColumn('bulk_email_recipients', 'outbound_message_id')) {
                    $table->unsignedBigInteger('outbound_message_id')->nullable();
                }
                if (! Schema::hasColumn('bulk_email_recipients', 'attempts')) {
                    $table->unsignedSmallInteger('attempts')->default(0);
                }
                if (! Schema::hasColumn('bulk_email_recipients', 'error_code')) {
                    $table->string('error_code', 80)->nullable();
                }
                if (! Schema::hasColumn('bulk_email_recipients', 'skip_reason')) {
                    $table->string('skip_reason', 80)->nullable();
                }
                if (! Schema::hasColumn('bulk_email_recipients', 'segments')) {
                    $table->unsignedSmallInteger('segments')->nullable();
                }
                if (! Schema::hasColumn('bulk_email_recipients', 'cost')) {
                    $table->decimal('cost', 12, 4)->nullable();
                }
                if (! Schema::hasColumn('bulk_email_recipients', 'currency')) {
                    $table->string('currency', 8)->nullable();
                }
                foreach (['accepted_at', 'delivered_at', 'read_at', 'failed_at', 'last_event_at'] as $column) {
                    if (! Schema::hasColumn('bulk_email_recipients', $column)) {
                        $table->timestamp($column)->nullable();
                    }
                }
                if (! Schema::hasColumn('bulk_email_recipients', 'metadata')) {
                    $table->json('metadata')->nullable();
                }
            });

            // Created before dropping the old unique so the job foreign key always has a leading index on MySQL.
            if (! Schema::hasIndex('bulk_email_recipients', 'bulk_email_recipients_job_channel_status_idx')) {
                Schema::table('bulk_email_recipients', function (Blueprint $table): void {
                    $table->index(['bulk_email_job_id', 'channel', 'status'], 'bulk_email_recipients_job_channel_status_idx');
                });
            }

            // Phone channels share the email column with email recipients, so uniqueness moves to idempotency_key.
            if (Schema::hasIndex('bulk_email_recipients', 'bulk_email_recipients_job_email_unique')) {
                Schema::table('bulk_email_recipients', function (Blueprint $table): void {
                    $table->dropUnique('bulk_email_recipients_job_email_unique');
                });
            }
        }

        if (Schema::hasTable('communication_outbound_messages')) {
            Schema::table('communication_outbound_messages', function (Blueprint $table): void {
                if (! Schema::hasColumn('communication_outbound_messages', 'error_code')) {
                    $table->string('error_code', 80)->nullable()->after('error_message');
                }
                if (! Schema::hasColumn('communication_outbound_messages', 'segments')) {
                    $table->unsignedSmallInteger('segments')->nullable();
                }
                if (! Schema::hasColumn('communication_outbound_messages', 'cost')) {
                    $table->decimal('cost', 12, 4)->nullable();
                }
                if (! Schema::hasColumn('communication_outbound_messages', 'currency')) {
                    $table->string('currency', 8)->nullable();
                }
                if (! Schema::hasColumn('communication_outbound_messages', 'read_at')) {
                    $table->timestamp('read_at')->nullable();
                }
                if (! Schema::hasColumn('communication_outbound_messages', 'last_event_at')) {
                    $table->timestamp('last_event_at')->nullable();
                }
                if (! Schema::hasColumn('communication_outbound_messages', 'idempotency_key')) {
                    $table->string('idempotency_key', 191)->nullable()->unique('comm_outbound_idempotency_unique');
                }
                if (! Schema::hasColumn('communication_outbound_messages', 'bulk_email_recipient_id')) {
                    $table->unsignedBigInteger('bulk_email_recipient_id')->nullable()->index('comm_outbound_recipient_idx');
                }
            });

            if (! Schema::hasIndex('communication_outbound_messages', 'comm_outbound_provider_msg_idx')) {
                Schema::table('communication_outbound_messages', function (Blueprint $table): void {
                    $table->index(['provider', 'provider_message_id'], 'comm_outbound_provider_msg_idx');
                });
            }
        }

        if (! Schema::hasTable('communication_consents')) {
            Schema::create('communication_consents', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('channel', 20);
                $table->string('destination', 191);
                // promotional | all
                $table->string('purpose', 20)->default('promotional');
                // opted_in | opted_out | suppressed
                $table->string('status', 20);
                $table->string('source', 60);
                $table->string('reason', 255)->nullable();
                $table->unsignedBigInteger('person_id')->nullable();
                $table->unsignedBigInteger('user_id')->nullable();
                $table->unsignedBigInteger('recorded_by_user_id')->nullable();
                $table->timestamp('opted_in_at')->nullable();
                $table->timestamp('opted_out_at')->nullable();
                $table->timestamp('suppressed_at')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->unique(['channel', 'destination', 'purpose'], 'comm_consents_channel_dest_purpose_unique');
                $table->index(['channel', 'status'], 'comm_consents_channel_status_idx');
            });
        }

        if (! Schema::hasTable('communication_consent_events')) {
            Schema::create('communication_consent_events', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('consent_id');
                $table->string('action', 30);
                $table->string('from_status', 20)->nullable();
                $table->string('to_status', 20);
                $table->string('source', 60);
                $table->string('reason', 255)->nullable();
                $table->unsignedBigInteger('actor_user_id')->nullable();
                $table->json('metadata')->nullable();
                $table->timestamps();

                $table->index(['consent_id', 'created_at'], 'comm_consent_events_consent_idx');
            });
        }

        if (! Schema::hasTable('communication_delivery_events')) {
            Schema::create('communication_delivery_events', function (Blueprint $table): void {
                $table->id();
                $table->string('provider', 40);
                $table->string('channel', 20);
                $table->string('provider_message_id', 191);
                $table->string('event_key', 191);
                $table->string('status', 30);
                $table->string('raw_status', 80)->nullable();
                $table->string('error_code', 80)->nullable();
                $table->string('error_message', 500)->nullable();
                $table->timestamp('occurred_at')->nullable();
                $table->boolean('applied')->default(false);
                $table->json('payload')->nullable();
                $table->timestamps();

                $table->unique(['provider', 'event_key'], 'comm_delivery_events_provider_key_unique');
                $table->index(['provider', 'provider_message_id'], 'comm_delivery_events_msg_idx');
                $table->index(['applied', 'created_at'], 'comm_delivery_events_applied_idx');
            });
        }

        if (! Schema::hasTable('communication_whatsapp_templates')) {
            Schema::create('communication_whatsapp_templates', function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('provider', 40);
                $table->string('name', 191);
                $table->string('language', 20);
                // marketing | utility | authentication
                $table->string('category', 30);
                // approved | pending | rejected | paused | disabled
                $table->string('status', 30)->default('pending');
                $table->text('body_preview')->nullable();
                $table->json('parameters')->nullable();
                // Twilio Content SID (HX...) when the provider is Twilio.
                $table->string('provider_template_id', 191)->nullable();
                $table->boolean('is_active')->default(true);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamp('status_synced_at')->nullable();
                $table->timestamps();

                $table->unique(['provider', 'name', 'language'], 'comm_wa_templates_provider_name_lang_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('communication_whatsapp_templates');
        Schema::dropIfExists('communication_delivery_events');
        Schema::dropIfExists('communication_consent_events');
        Schema::dropIfExists('communication_consents');
    }
};
