<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        DB::table('products')->insert([
            [
                'name' => 'shirt',
                'color' => 'black',
                'price_cfa' => 4000,
                'stock_quantity' => 15,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'shirt',
                'color' => 'white',
                'price_cfa' => 3500,
                'stock_quantity' => 5,
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'name' => 'bag',
                'color' => 'brown',
                'price_cfa' => 12000,
                'stock_quantity' => 2,
                'created_at' => now(),
                'updated_at' => now(),
            ]
        ]);
    }
}
        
    

