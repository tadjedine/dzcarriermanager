<?php

declare(strict_types=1);

namespace Module\DzCarrierManager\Carrier\Guepex;

use Doctrine\DBAL\Connection;

/**
 * Handles incoming webhooks from Guepex (Yalidine).
 *
 * Implements CRC challenge-response verification, HMAC-SHA256 signature validation,
 * event deduplication, and database status/history updates.
 *
 * @see https://guepex.app/app/dev/docs/api/
 */
class GuepexWebhookHandler
{
    /**
     * Verify HMAC-SHA256 signature from the X_YALIDINE_SIGNATURE header.
     */
    public function validateSignature(string $rawPayload, ?string $receivedSignature, ?string $secretKey): bool
    {
        // If no secret is configured yet, reject or require configuration
        if (empty($secretKey)) {
            return false;
        }

        if (empty($receivedSignature)) {
            return false;
        }

        $computed = hash_hmac('sha256', $rawPayload, $secretKey);

        return hash_equals($computed, $receivedSignature);
    }

    /**
     * Process the parsed webhook payload.
     *
     * @return array{success: bool, message: string, processed: int, skipped: int}
     */
    public function handle(string $rawPayload, Connection $db, string $prefix): array
    {
        $payload = json_decode($rawPayload, true);

        if (!is_array($payload) || empty($payload['type']) || !isset($payload['events']) || !is_array($payload['events'])) {
            return [
                'success' => false,
                'message' => 'Invalid JSON payload structure.',
                'processed' => 0,
                'skipped' => 0,
            ];
        }

        $type = (string) $payload['type'];
        $events = $payload['events'];
        $processed = 0;
        $skipped = 0;

        foreach ($events as $event) {
            if (!is_array($event)) {
                continue;
            }

            $eventId = isset($event['event_id']) ? (string) $event['event_id'] : '';
            $occurredAt = !empty($event['occurred_at']) ? (string) $event['occurred_at'] : date('Y-m-d H:i:s');
            $data = $event['data'] ?? [];

            // Deduplication: check if event_id already logged in cm_parcel_histories
            if (!empty($eventId) && $this->isEventAlreadyProcessed($eventId, $db, $prefix)) {
                $skipped++;
                continue;
            }

            $handled = match ($type) {
                'parcel_status_updated' => $this->handleStatusUpdated($data, $eventId, $occurredAt, $event, $db, $prefix),
                'parcel_created' => $this->handleParcelCreated($data, $eventId, $occurredAt, $event, $db, $prefix),
                'parcel_edited' => $this->handleParcelEdited($data, $eventId, $occurredAt, $event, $db, $prefix),
                'parcel_deleted' => $this->handleParcelDeleted($data, $eventId, $occurredAt, $event, $db, $prefix),
                'parcel_payment_updated' => $this->handlePaymentUpdated($data, $eventId, $occurredAt, $event, $db, $prefix),
                default => false,
            };

            if ($handled) {
                $processed++;
            } else {
                $skipped++;
            }
        }

        return [
            'success' => true,
            'message' => sprintf('Processed %d event(s), skipped %d.', $processed, $skipped),
            'processed' => $processed,
            'skipped' => $skipped,
        ];
    }

    /**
     * Check if this event_id has already been recorded.
     */
    private function isEventAlreadyProcessed(string $eventId, Connection $db, string $prefix): bool
    {
        $count = $db->fetchOne(
            "SELECT COUNT(*) FROM `{$prefix}cm_parcel_histories` WHERE `raw_payload` LIKE :pattern",
            ['pattern' => '%"event_id":"' . $eventId . '"%']
        );

        return (int) $count > 0;
    }

    /**
     * Handle parcel_status_updated event.
     */
    private function handleStatusUpdated(array $data, string $eventId, string $occurredAt, array $rawEvent, Connection $db, string $prefix): bool
    {
        $tracking = $data['tracking'] ?? null;
        $status = $data['status'] ?? null;
        $reason = $data['reason'] ?? null;

        if (empty($tracking) || empty($status)) {
            return false;
        }

        $parcel = $db->fetchAssociative(
            "SELECT * FROM `{$prefix}cm_parcels` WHERE `tracking` = :tracking",
            ['tracking' => $tracking]
        );

        if (!$parcel) {
            return false;
        }

        $updateFields = [
            'status' => $status,
            'status_changed_at' => $occurredAt,
            'updated_at' => date('Y-m-d H:i:s'),
        ];

        // Set delivered_at if status is delivered
        if ($status === GuepexParcelStatus::LIVRE && empty($parcel['delivered_at'])) {
            $updateFields['delivered_at'] = $occurredAt;
        }

        // Set shipped_at if entering shipping phase and not set yet
        if (in_array($status, [GuepexParcelStatus::EXPEDIE, GuepexParcelStatus::RAMASSE, GuepexParcelStatus::EN_TRANSIT], true) && empty($parcel['shipped_at'])) {
            $updateFields['shipped_at'] = $occurredAt;
        }

        $db->update($prefix . 'cm_parcels', $updateFields, ['id' => $parcel['id']]);

        // Insert history record
        $db->insert($prefix . 'cm_parcel_histories', [
            'parcel_id' => (int) $parcel['id'],
            'status' => $status,
            'reason' => $reason ?: 'Status updated via Guepex webhook.',
            'raw_payload' => json_encode($rawEvent),
            'occurred_at' => $occurredAt,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    /**
     * Handle parcel_created event.
     */
    private function handleParcelCreated(array $data, string $eventId, string $occurredAt, array $rawEvent, Connection $db, string $prefix): bool
    {
        $orderId = isset($data['order_id']) ? (int) $data['order_id'] : 0;
        $tracking = $data['tracking'] ?? null;
        $label = $data['label'] ?? null;
        $importId = isset($data['import_id']) ? (int) $data['import_id'] : null;

        $parcel = null;
        if ($orderId > 0) {
            $parcel = $db->fetchAssociative(
                "SELECT * FROM `{$prefix}cm_parcels` WHERE `order_id` = :orderId",
                ['orderId' => $orderId]
            );
        }

        if (!$parcel && !empty($tracking)) {
            $parcel = $db->fetchAssociative(
                "SELECT * FROM `{$prefix}cm_parcels` WHERE `tracking` = :tracking",
                ['tracking' => $tracking]
            );
        }

        if (!$parcel) {
            return false;
        }

        $updateFields = [
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if (!empty($tracking)) {
            $updateFields['tracking'] = $tracking;
        }
        if (!empty($label)) {
            $updateFields['label_url'] = $label;
        }
        if (!empty($importId)) {
            $updateFields['import_id'] = $importId;
        }

        $db->update($prefix . 'cm_parcels', $updateFields, ['id' => $parcel['id']]);

        $db->insert($prefix . 'cm_parcel_histories', [
            'parcel_id' => (int) $parcel['id'],
            'status' => $parcel['status'],
            'reason' => 'Parcel created notification received from Guepex webhook.',
            'raw_payload' => json_encode($rawEvent),
            'occurred_at' => $occurredAt,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    /**
     * Handle parcel_edited event.
     */
    private function handleParcelEdited(array $data, string $eventId, string $occurredAt, array $rawEvent, Connection $db, string $prefix): bool
    {
        $tracking = $data['tracking'] ?? null;
        $label = $data['label'] ?? null;

        if (empty($tracking)) {
            return false;
        }

        $parcel = $db->fetchAssociative(
            "SELECT * FROM `{$prefix}cm_parcels` WHERE `tracking` = :tracking",
            ['tracking' => $tracking]
        );

        if (!$parcel) {
            return false;
        }

        if (!empty($label)) {
            $db->update($prefix . 'cm_parcels', [
                'label_url' => $label,
                'updated_at' => date('Y-m-d H:i:s'),
            ], ['id' => $parcel['id']]);
        }

        $db->insert($prefix . 'cm_parcel_histories', [
            'parcel_id' => (int) $parcel['id'],
            'status' => $parcel['status'],
            'reason' => 'Parcel edited on Guepex platform (label updated).',
            'raw_payload' => json_encode($rawEvent),
            'occurred_at' => $occurredAt,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    /**
     * Handle parcel_deleted event.
     */
    private function handleParcelDeleted(array $data, string $eventId, string $occurredAt, array $rawEvent, Connection $db, string $prefix): bool
    {
        $tracking = $data['tracking'] ?? null;

        if (empty($tracking)) {
            return false;
        }

        $parcel = $db->fetchAssociative(
            "SELECT * FROM `{$prefix}cm_parcels` WHERE `tracking` = :tracking",
            ['tracking' => $tracking]
        );

        if (!$parcel) {
            return false;
        }

        $db->update($prefix . 'cm_parcels', [
            'status' => GuepexParcelStatus::ANNULE,
            'status_changed_at' => $occurredAt,
            'updated_at' => date('Y-m-d H:i:s'),
        ], ['id' => $parcel['id']]);

        $db->insert($prefix . 'cm_parcel_histories', [
            'parcel_id' => (int) $parcel['id'],
            'status' => GuepexParcelStatus::ANNULE,
            'reason' => 'Parcel was deleted on Guepex platform.',
            'raw_payload' => json_encode($rawEvent),
            'occurred_at' => $occurredAt,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return true;
    }

    /**
     * Handle parcel_payment_updated event.
     */
    private function handlePaymentUpdated(array $data, string $eventId, string $occurredAt, array $rawEvent, Connection $db, string $prefix): bool
    {
        $tracking = $data['tracking'] ?? null;
        $paymentStatus = $data['status'] ?? 'unknown';
        $paymentId = $data['payment_id'] ?? null;

        if (empty($tracking)) {
            return false;
        }

        $parcel = $db->fetchAssociative(
            "SELECT * FROM `{$prefix}cm_parcels` WHERE `tracking` = :tracking",
            ['tracking' => $tracking]
        );

        if (!$parcel) {
            return false;
        }

        $reason = sprintf('Carrier payment updated: status="%s"', $paymentStatus);
        if ($paymentId) {
            $reason .= sprintf(', payment_id="%s"', $paymentId);
        }

        $db->insert($prefix . 'cm_parcel_histories', [
            'parcel_id' => (int) $parcel['id'],
            'status' => $parcel['status'],
            'reason' => $reason,
            'raw_payload' => json_encode($rawEvent),
            'occurred_at' => $occurredAt,
            'created_at' => date('Y-m-d H:i:s'),
        ]);

        return true;
    }
}
