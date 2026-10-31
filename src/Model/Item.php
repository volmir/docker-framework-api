<?php
declare(strict_types=1);

namespace App\Model;

class Item
{
    public int|string|null $id = null;
    public ?string $name = null;
    public ?string $phone = null;
    public ?string $key = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;
}
