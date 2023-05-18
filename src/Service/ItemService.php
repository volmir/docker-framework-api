<?php

namespace App\Service;

use App\Repository\ItemRepository;
use App\Validator\ItemValidator;
use App\Logger\AppLogger;
use App\Core\Log\Logger;

class ItemService
{    
    protected ItemRepository $itemRepository;
    protected ItemValidator $itemValidator;
    protected Logger $logger;

    public function __construct()
    {
        $this->itemRepository = new ItemRepository();
        $this->itemValidator = new ItemValidator();
        $this->logger = AppLogger::init();
    }    

    public function find($id) 
    {
        $result = [];
        $id = (int)$id;

        if ($this->itemValidator->validateId($id)) {         
            $result = $this->itemRepository->find($id);
        } else {
            $result = [
                'error' => 'Bad Request',
                'errors' => $this->itemValidator->getErrors(),
            ];
        }
        return $result;
    }

    public function findAll(int $start = 0, int $stop = 20) 
    {
        return $this->itemRepository->findAll($start, $stop);
    }

    public function store(array $params = [])
    {
        $params['created_at'] = date('Y-m-d H:i:s');
        $params['updated_at'] = date('Y-m-d H:i:s');

        if ($this->itemValidator->validateStore($params)) {  
            if ($this->itemRepository->store($params)) {
                $lastInsertId = $this->itemRepository->lastInsertId();
                $result = [
                    'success' => 'Created',
                    'item' => $this->itemRepository->find($lastInsertId['LAST_INSERT_ID()']),
                ];        
            } else {
                $result = ['error' => 'DB error'];
            }              
        } else {
            $result = [
                'error' => 'Bad Request',
                'errors' => $this->itemValidator->getErrors(),
            ];
        }
        return $result;        
    }
    
    public function update(array $params = [])
    {
        $result = [];
        $params['update_at'] = date('Y-m-d H:i:s');
        $params['id'] = (int)($params['id'] ?? '');

        if ($this->itemValidator->validateUpdate($params)) {  
            $item = $this->itemRepository->find($params['id']);           
            if (!empty($item)) {            
                if ($this->itemRepository->update($params)) {    
                    $result = ['success' => 'Updated'];
    
                    $this->logger->log(\Psr\Log\LogLevel::NOTICE, 'Item ID: ' . $params['id'] . ' updated');  
                } else {
                    $result = ['error' => 'DB error'];
                }
            } else {
                $result = ['error' => 'Bad Request'];
            }
        } else {
            $result = [
                'error' => 'Bad Request',
                'errors' => $this->itemValidator->getErrors(),
            ];
        }        
        return $result;
    }

    public function destroy($id) 
    {
        $result = [];
        $id = (int)$id;

        if ($this->itemValidator->validateId($id)) {                  
            if ($this->itemRepository->destroy($id)) {
                $result = ['success' => 'Deleted'];
            }
        } else {
            $result = [
                'error' => 'Bad Request',
                'errors' => $this->itemValidator->getErrors(),
            ];
        }
        return $result;
    }
}
