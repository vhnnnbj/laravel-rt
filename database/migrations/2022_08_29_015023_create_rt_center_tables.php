<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {

    /**
     * The database schema.
     *
     * @var \Illuminate\Database\Schema\Builder
     */
    protected $schema;

    /**
     * Create a new migration instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->schema = Schema::connection('rt_center');
    }

    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        $this->schema->create('reset_transact', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('transact_id', 32)->index();
            $table->text('transact_rollback');
            $table->tinyInteger('action')->default(0);
            $table->text('xids_info');
            $table->dateTime('created_at')->useCurrent();
            $table->unique('transact_id');
        });
        $this->schema->create('reset_transact_sql', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('request_id', 32);
            $table->string('transact_id', 32);
            $table->string('chain_id', 512);
            $table->tinyInteger('transact_status')->default(0);
            $table->string('connection', 32);
            $table->longText('sql');
            $table->longText('values')->nullable();
            $table->integer('result')->default(0);
            $table->tinyInteger('check_result')->default(0);
            $table->dateTime('created_at')->useCurrent();
            $table->index('request_id');
            $table->index('transact_id');
        });
        $this->schema->create('reset_transact_req', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('request_id', 32)->index();
            $table->string('transact_id', 32);
            $table->text('response');
            $table->dateTime('created_at')->useCurrent();
            $table->unique('request_id');
            $table->index('transact_id');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        $this->schema->dropIfExists('reset_transact');
        $this->schema->dropIfExists('reset_transact_sql');
        $this->schema->dropIfExists('reset_transact_req');
    }
};
