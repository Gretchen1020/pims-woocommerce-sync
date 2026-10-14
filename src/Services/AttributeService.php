<?php
declare(strict_types=1);

namespace Pims\Services;

use Pims\Http\HttpException;
use Pims\Repositories\AttributeRepository;

final class AttributeService
{
    private const TYPES = ['text', 'number', 'boolean', 'select', 'unit'];

    public static function list(): array
    {
        return AttributeRepository::findAll();
    }

    public static function get(int $id): array
    {
        $a = AttributeRepository::findById($id);
        if (!$a) {
            throw new HttpException(404, 'not_found', 'Attribute not found.');
        }
        return $a;
    }

    public static function create(array $input): array
    {
        $name = trim((string) ($input['name'] ?? ''));
        $type = (string) ($input['data_type'] ?? '');
        $unit = isset($input['unit']) ? trim((string) $input['unit']) : null;

        $errors = [];
        if ($name === '') {
            $errors['name'] = 'Name is required.';
        }
        if (!in_array($type, self::TYPES, true)) {
            $errors['data_type'] = 'Invalid data type. Must be one of: ' . implode(', ', self::TYPES);
        }
        if ($type === 'unit' && ($unit === null || $unit === '')) {
            $errors['unit'] = 'Unit is required for unit-type attributes.';
        }
        if ($type !== 'unit') {
            $unit = null;
        }
        if ($errors) {
            throw new HttpException(422, 'validation_error', 'Validation failed.', $errors);
        }
        if (AttributeRepository::findByName($name)) {
            throw new HttpException(409, 'duplicate', 'An attribute with that name already exists.', ['name' => 'Duplicate name.']);
        }

        $attr = AttributeRepository::create($name, $type, $unit);

        // Seed options for select type
        if ($type === 'select' && !empty($input['options']) && is_array($input['options'])) {
            foreach ($input['options'] as $opt) {
                $val = trim((string) $opt);
                if ($val !== '') {
                    AttributeRepository::addOption((int) $attr['id'], $val);
                }
            }
            $attr = AttributeRepository::findById((int) $attr['id']);
        }
        return $attr;
    }

    public static function update(int $id, array $input): array
    {
        $existing = AttributeRepository::findById($id);
        if (!$existing) {
            throw new HttpException(404, 'not_found', 'Attribute not found.');
        }

        $name = trim((string) ($input['name'] ?? $existing['name']));
        if ($name === '') {
            throw new HttpException(422, 'validation_error', 'Validation failed.', ['name' => 'Name is required.']);
        }
        $dup = AttributeRepository::findByName($name);
        if ($dup && (int) $dup['id'] !== $id) {
            throw new HttpException(409, 'duplicate', 'An attribute with that name already exists.', ['name' => 'Duplicate name.']);
        }

        // Data type cannot change once any product has a value
        if (isset($input['data_type']) && $input['data_type'] !== $existing['data_type']) {
            if (AttributeRepository::hasValues($id)) {
                throw new HttpException(409, 'in_use', 'Cannot change data type after products have values for this attribute.');
            }
            // Allow type change only if no values — but schema stores type on the row; re-create would be safer.
            // Spec: "An attribute's data type cannot change once any product has a value for it."
            // If no values, we still don't support type change in this MVP to keep it simple.
            throw new HttpException(422, 'validation_error', 'Changing data type is not supported. Create a new attribute instead.');
        }

        $unit = $existing['unit'];
        if ($existing['data_type'] === 'unit' && array_key_exists('unit', $input)) {
            $unit = trim((string) $input['unit']) ?: null;
            if ($unit === null) {
                throw new HttpException(422, 'validation_error', 'Validation failed.', ['unit' => 'Unit is required.']);
            }
        }

        return AttributeRepository::update($id, $name, $unit);
    }

    public static function delete(int $id): void
    {
        if (!AttributeRepository::findById($id)) {
            throw new HttpException(404, 'not_found', 'Attribute not found.');
        }
        if (AttributeRepository::hasValues($id)) {
            throw new HttpException(409, 'in_use', 'Cannot delete an attribute that still has product values. Remove the values first.');
        }
        if (AttributeRepository::isAssignedToCategory($id)) {
            throw new HttpException(409, 'in_use', 'Cannot delete an attribute that is assigned to a category. Unassign it first.');
        }
        AttributeRepository::delete($id);
    }

    // ── Options ────────────────────────────────────────────────────────

    public static function addOption(int $attributeId, array $input): array
    {
        $attr = AttributeRepository::findById($attributeId);
        if (!$attr) {
            throw new HttpException(404, 'not_found', 'Attribute not found.');
        }
        if ($attr['data_type'] !== 'select') {
            throw new HttpException(422, 'validation_error', 'Options can only be added to select attributes.');
        }
        $value = trim((string) ($input['value'] ?? ''));
        if ($value === '') {
            throw new HttpException(422, 'validation_error', 'Validation failed.', ['value' => 'Value is required.']);
        }
        try {
            return AttributeRepository::addOption($attributeId, $value);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new HttpException(409, 'duplicate', 'That option already exists.', ['value' => 'Duplicate option.']);
            }
            throw $e;
        }
    }

    public static function updateOption(int $attributeId, int $optionId, array $input): array
    {
        $opt = AttributeRepository::findOption($optionId);
        if (!$opt || (int) $opt['attribute_id'] !== $attributeId) {
            throw new HttpException(404, 'not_found', 'Option not found.');
        }
        $value = trim((string) ($input['value'] ?? ''));
        if ($value === '') {
            throw new HttpException(422, 'validation_error', 'Validation failed.', ['value' => 'Value is required.']);
        }
        try {
            return AttributeRepository::updateOption($optionId, $value);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                throw new HttpException(409, 'duplicate', 'That option already exists.', ['value' => 'Duplicate option.']);
            }
            throw $e;
        }
    }

    public static function deleteOption(int $attributeId, int $optionId): void
    {
        $opt = AttributeRepository::findOption($optionId);
        if (!$opt || (int) $opt['attribute_id'] !== $attributeId) {
            throw new HttpException(404, 'not_found', 'Option not found.');
        }
        if (AttributeRepository::optionHasValues($optionId)) {
            throw new HttpException(409, 'in_use', 'Cannot delete an option that is used by products.');
        }
        AttributeRepository::deleteOption($optionId);
    }
}
