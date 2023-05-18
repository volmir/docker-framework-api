<?php

namespace App\Validator;

use App\Core\Validator;

class ItemValidator extends Validator
{    
    public function validateId($id) 
    {
        if (empty($id)) {         
            $this->setError('Field `id` is incorrect');
        }
        if (!empty($id) && $id <= 0) {         
            $this->setError('Field `id` is less or equal zero');
        }

        return $this->isValid();
    }

    public function validateStore(array $params = [])
    {
        if (empty($params['name'])) {         
            $this->setError('Field `name` is empty');
        }
        if (!empty($params['name']) && strlen($params['name']) > 255) { 
            $this->setError('Field `name` lenght is more that 255 symbols');
        }
        if (empty($params['phone'])) {         
            $this->setError('Field `phone` is empty');
        }
        if (!empty($params['phone']) && strlen($params['phone']) > 15) { 
            $this->setError('Field `phone` lenght is more that 15 symbols');
        }
        if (empty($params['key'])) {         
            $this->setError('Field `key` is empty');
        }
        if (!empty($params['key']) && strlen($params['key']) > 25) { 
            $this->setError('Field `key` lenght is more that 25 symbols');
        }

        return $this->isValid();        
    }
    
    public function validateUpdate(array $params = [])
    {
        if (empty($params['id'])) {         
            $this->setError('Field `id` is empty');
        }
        if (!empty($params['id']) && $params['id'] <= 0) {         
            $this->setError('Field `id` is less or equal zero');
        }
        if (empty($params['name'])) {         
            $this->setError('Field `name` is empty');
        }
        if (!empty($params['name']) && strlen($params['name']) > 255) { 
            $this->setError('Field `name` lenght is more that 255 symbols');
        }
        if (empty($params['phone'])) {         
            $this->setError('Field `phone` is empty');
        }
        if (!empty($params['phone']) && strlen($params['phone']) > 15) { 
            $this->setError('Field `phone` lenght is more that 15 symbols');
        }
        if (empty($params['key'])) {         
            $this->setError('Field `key` is empty');
        }
        if (!empty($params['key']) && strlen($params['key']) > 25) { 
            $this->setError('Field `key` lenght is more that 25 symbols');
        }

        return $this->isValid();        
    }   
}
