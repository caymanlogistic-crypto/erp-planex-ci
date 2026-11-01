<?php

namespace App\Service;

use PDO;

final class ContractorContactService
{
    public static function ensureTable(PDO $pdo): void
    {
        $pdo->query('SELECT 1 FROM contractor_contacts LIMIT 0');
    }

    public static function loadByContractorId(PDO $pdo, int $contractorId): array
    {
        self::ensureTable($pdo);

        $stmt = $pdo->prepare(
            'SELECT *
               FROM contractor_contacts
              WHERE contractor_id = ?
              ORDER BY is_primary DESC, id ASC'
        );
        $stmt->execute([$contractorId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    public static function normalizeSubmittedContacts(mixed $rawContacts): array
    {
        $rows = is_array($rawContacts) ? $rawContacts : [];
        $contacts = [];
        $errors = [];
        $primaryIndex = null;

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $contactPerson = self::normalizeText($row['contact_person'] ?? '');
            $phone = self::normalizeText($row['phone'] ?? '');
            $email = trim((string) ($row['email'] ?? ''));
            $comment = trim((string) ($row['comment'] ?? ''));
            $isPrimary = self::isChecked($row['is_primary'] ?? null);
            $isDocumentEmail = self::isChecked($row['is_document_email'] ?? null);

            $isMeaningful = $contactPerson !== '' || $phone !== '' || $email !== '' || $comment !== '';
            if (!$isMeaningful) {
                continue;
            }

            if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
                $errors[$index]['email'] = 'Некорректный email';
            }

            if ($isPrimary && $primaryIndex === null) {
                $primaryIndex = count($contacts);
            }

            $contacts[] = [
                'contact_person' => $contactPerson !== '' ? $contactPerson : null,
                'phone' => $phone !== '' ? $phone : null,
                'email' => $email !== '' ? $email : null,
                'comment' => $comment !== '' ? $comment : null,
                'is_primary' => 0,
                'is_document_email' => $isDocumentEmail && $email !== '' ? 1 : 0,
            ];
        }

        if ($contacts !== []) {
            $primaryIndex = $primaryIndex ?? 0;
            foreach ($contacts as $index => &$contact) {
                $contact['is_primary'] = $index === $primaryIndex ? 1 : 0;
            }
            unset($contact);
        }

        return [
            'contacts' => $contacts,
            'errors' => $errors,
        ];
    }

    public static function replaceForContractor(PDO $pdo, int $contractorId, array $contacts, ?int $userId, ?string $role): void
    {
        self::ensureTable($pdo);

        $pdo->prepare('DELETE FROM contractor_contacts WHERE contractor_id = ?')->execute([$contractorId]);

        if ($contacts === []) {
            return;
        }

        $insert = $pdo->prepare(
            'INSERT INTO contractor_contacts (
                contractor_id,
                contact_person,
                phone,
                email,
                is_primary,
                is_document_email,
                comment,
                created_by_user_id,
                created_by_role,
                updated_by_user_id,
                updated_by_role
            ) VALUES (
                :contractor_id,
                :contact_person,
                :phone,
                :email,
                :is_primary,
                :is_document_email,
                :comment,
                :created_by_user_id,
                :created_by_role,
                :updated_by_user_id,
                :updated_by_role
            )'
        );

        foreach ($contacts as $contact) {
            $insert->execute([
                ':contractor_id' => $contractorId,
                ':contact_person' => $contact['contact_person'],
                ':phone' => $contact['phone'],
                ':email' => $contact['email'],
                ':is_primary' => (int) ($contact['is_primary'] ?? 0),
                ':is_document_email' => (int) ($contact['is_document_email'] ?? 0),
                ':comment' => $contact['comment'],
                ':created_by_user_id' => $userId,
                ':created_by_role' => $role,
                ':updated_by_user_id' => $userId,
                ':updated_by_role' => $role,
            ]);
        }
    }

    public static function buildPrimaryContactSubquery(string $column): string
    {
        return "(SELECT cc.$column
                   FROM contractor_contacts cc
                  WHERE cc.contractor_id = c.id
                  ORDER BY cc.is_primary DESC, cc.id ASC
                  LIMIT 1)";
    }

    public static function buildDocumentEmailSubquery(): string
    {
        return "(SELECT cc.email
                   FROM contractor_contacts cc
                  WHERE cc.contractor_id = c.id
                    AND cc.is_document_email = 1
                  ORDER BY cc.is_primary DESC, cc.id ASC
                  LIMIT 1)";
    }

    private static function normalizeText(mixed $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        return preg_replace('/\s+/u', ' ', $value) ?? $value;
    }

    private static function isChecked(mixed $value): bool
    {
        return in_array((string) $value, ['1', 'on', 'yes', 'true'], true);
    }
}
