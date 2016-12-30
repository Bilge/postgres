<?php

namespace Amp\Postgres;

interface Operation {
    /**
     * @param callable $onComplete Callback executed when the operation completes or the object is destroyed.
     */
    public function onComplete(callable $onComplete);
}
