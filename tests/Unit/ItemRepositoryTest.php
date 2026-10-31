<?php

namespace App\Tests\Unit;

use PHPUnit\Framework\TestCase;
use App\Repository\ItemRepository;

class ItemRepositoryTest extends TestCase
{
    protected $itemRepository;

    protected function setUp(): void
    {
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
