<?php

namespace app\controller\AiDev;

use think\Request;

class BaseController
{
    protected $request;

    public function __construct(Request $request)
    {
        $this->request = $request;
    }

    protected function ok($data = [], $message = 'ok')
    {
        return json([
            'code' => 0,
            'message' => $message,
            'data' => $data,
        ]);
    }

    protected function fail($message, $code = 400, $data = [])
    {
        return json([
            'code' => $code,
            'message' => $message,
            'data' => $data,
        ], $code);
    }
}
