<?php

namespace Amp\Postgres;

use Amp\Sql\Link as SqlLink;

interface Link extends Executor, SqlLink
{
}
