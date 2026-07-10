<?php

namespace App\Support;

use Symfony\Component\Process\Process;

class ProcessOutput
{
    /**
     * The TAIL of a failed process's output for error messages — python
     * tracebacks and abort reasons sit at the end, after startup noise.
     */
    public static function tail(Process $process, int $chars = 1500): string
    {
        $output = trim($process->getErrorOutput()) ?: trim($process->getOutput());

        return mb_strlen($output) > $chars
            ? '…'.mb_substr($output, -$chars)
            : $output;
    }
}
