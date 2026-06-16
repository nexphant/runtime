<?php

namespace nexphant\Runtime\Supervisor;

enum WorkerCommand: int
{
    case RELOAD = 1;
    case SHUTDOWN = 2;
    case DRAIN = 3;
    case HEALTH_CHECK = 4;
    case CONFIG_UPDATE = 5;
}
