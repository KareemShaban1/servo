<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class AddShowInTab3eenColumnToProductsTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        if (!Schema::hasColumn('products', 'show_in_tab3een')) {
            Schema::table('products', function (Blueprint $table) {
                $table->boolean('show_in_tab3een')->default(false)->after('active_in_app');
            });
        }
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        if (Schema::hasColumn('products', 'show_in_tab3een')) {
            Schema::table('products', function (Blueprint $table) {
                $table->dropColumn('show_in_tab3een');
            });
        }
    }
}
