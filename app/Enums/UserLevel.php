<?php

namespace App\Enums;

enum UserLevel: string
{
    case Honcho = 'honcho';
    case Reporter = 'reporter';
    case Buttonpusher = 'buttonpusher';
}
