<?php

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Repository\ItemRepository;

class ItemRepositoryTest extends TestCase
{
    protected $itemRepository;

    protected function setUp(): void
    {
        $config = require(__DIR__ . '/../../config/main.php');
        
        define('DB_HOST', $config['db']['test']['host']);
        define('DB_PORT', $config['db']['test']['port']);
        define('DB_DBNAME', $config['db']['test']['dbname']);
        define('DB_USERNAME', $config['db']['test']['username']);
        define('DB_PASSWORD', $config['db']['test']['password']);

        $this->itemRepository = new ItemRepository();
    }

    public function test_item_repository_find_all(): void
    {
        $this->assertIsArray($this->itemRepository->findAll());
    }

    public function test_item_repository_find_one(): void
    {
        $item = $this->itemRepository->find(4);
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('name', $item);
        $this->assertArrayHasKey('phone', $item);
        $this->assertArrayHasKey('key', $item);
    }    
}
