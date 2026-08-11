<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Seeder;

class ProductSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $products = [
            ['ブレンドコーヒー豆 200g', 1200, 40, '食品', '深煎りのブレンド。中挽きで提供しています。'],
            ['有機緑茶 ティーバッグ 50個入', 980, 25, '食品', null],
            ['プレーンTシャツ（白・M）', 2500, 0, '衣料品', '在庫切れ表示の確認用サンプルです。'],
            ['コットンパーカー（グレー・L）', 6800, 12, '衣料品', '裏起毛タイプ。'],
            ['ワイヤレスマウス', 3480, 8, '家電', 'Bluetooth 接続、単三電池1本で動作します。'],
            ['メカニカルキーボード 87キー', 12800, 5, '家電', '赤軸。日本語配列。'],
            ['USB-C ハブ 7ポート', 4980, 30, '家電', null],
            ['ノイズキャンセリングイヤホン', 19800, 3, '家電', '外音取り込みモード搭載。'],
            ['入門 PHP プログラミング', 3200, 15, '書籍', '基礎文法からオブジェクト指向まで。'],
            ['実践 Laravel アプリケーション開発', 3800, 7, '書籍', null],
            ['データベース設計の基礎', 2980, 20, '書籍', '正規化と ER 図の考え方を解説。'],
            ['Docker 実践ガイド 第3版', 3600, 0, '書籍', '在庫切れ表示の確認用サンプルです。'],
            ['A5 方眼ノート 5冊セット', 890, 100, 'その他', null],
            ['卓上加湿器 USB給電', 2780, 18, 'その他', '静音設計。オフィス向け。'],
            ['モバイルバッテリー 10000mAh', 4200, 22, 'その他', 'PD 対応、最大 20W 出力。'],
        ];

        foreach ($products as [$name, $price, $stock, $category, $description]) {
            Product::create([
                'name' => $name,
                'price' => $price,
                'stock' => $stock,
                'category' => $category,
                'description' => $description,
            ]);
        }
    }
}
