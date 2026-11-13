<?php

declare(strict_types=1);

return [

    /*
     * Cache store holding the slots. It must support locks (redis, memcached,
     * database, file, array, ...). Null uses the application's default store.
     */
    'store' => env('CONCURRENCY_LIMIT_STORE'),

    /*
     * When the store fails, let the request or job run without a slot (true)
     * or reject it with a 503 / release the job (false). Each limit can
     * override this with failOpen() or failClosed().
     */
    'fail_open' => true,

];
