<?php
declare(strict_types=1);

namespace App\Validator;

use App\Core\Validator;

class ItemValidator extends Validator
{
    public function validateId(int $id): bool
    {
        if (empty($id)) {
            $this->setError('Field `id` is incorrect');
        }
        if (!empty($id) && $id <= 0) {
            $this->setError('Field `id` is less or equal zero');
        }

        return $this->isValid();
    }

    public function validateStore(array $params = []): bool
    {
        if (!isset($params['name']) || !is_string($params['name']) || $params['name'] === '') {
            $this->setError('Field `name` is empty');
        }
        if (
            isset($params['name'])
            && is_string($params['name'])
            && $params['name'] !== ''
            && strlen($params['name']) > 255
        ) {
            $this->setError('Field `name` lenght is more that 255 symbols');
        }
        if (!isset($params['phone']) || !is_string($params['phone']) || $params['phone'] === '') {
            $this->setError('Field `phone` is empty');
        }
        if (
            isset($params['phone'])
            && is_string($params['phone'])
            && $params['phone'] !== ''
            && strlen($params['phone']) > 15
        ) {
            $this->setError('Field `phone` lenght is more that 15 symbols');
        }
        if (!isset($params['key']) || !is_string($params['key']) || $params['key'] === '') {
            $this->setError('Field `key` is empty');
        }
        if (
            isset($params['key'])
            && is_string($params['key'])
            && $params['key'] !== ''
            && strlen($params['key']) > 25
        ) {
            $this->setError('Field `key` lenght is more that 25 symbols');
        }

        return $this->isValid();
    }

    public function validateUpdate(array $params = []): bool
    {
        if (empty($params['id'])) {
            $this->setError('Field `id` is empty');
        }
        if (!empty($params['id']) && $params['id'] <= 0) {
            $this->setError('Field `id` is less or equal zero');
        }
        if (!isset($params['name']) || !is_string($params['name']) || $params['name'] === '') {
            $this->setError('Field `name` is empty');
        }
        if (
            isset($params['name'])
            && is_string($params['name'])
            && $params['name'] !== ''
            && strlen($params['name']) > 255
        ) {
            $this->setError('Field `name` lenght is more that 255 symbols');
        }
        if (!isset($params['phone']) || !is_string($params['phone']) || $params['phone'] === '') {
            $this->setError('Field `phone` is empty');
        }
        if (
            isset($params['phone'])
            && is_string($params['phone'])
            && $params['phone'] !== ''
            && strlen($params['phone']) > 15
        ) {
            $this->setError('Field `phone` lenght is more that 15 symbols');
        }
        if (!isset($params['key']) || !is_string($params['key']) || $params['key'] === '') {
            $this->setError('Field `key` is empty');
        }
        if (
            isset($params['key'])
            && is_string($params['key'])
            && $params['key'] !== ''
            && strlen($params['key']) > 25
        ) {
            $this->setError('Field `key` lenght is more that 25 symbols');
        }

        return $this->isValid();
    }
}
