<?php

namespace app;

use think\exception\Handle;
use think\Response;
use Throwable;

class ExceptionHandle extends Handle
{
    public function render($request, Throwable $e): Response
    {
        $path = $request->pathinfo();
        if (strpos($path, 'api/') === 0) {
            $status = 500;
            if ($e instanceof \RuntimeException) {
                $status = 400;
            }
            return json([
                'code' => $status,
                'message' => $e->getMessage(),
                'data' => [],
            ], $status);
        }

        return parent::render($request, $e);
    }
}
