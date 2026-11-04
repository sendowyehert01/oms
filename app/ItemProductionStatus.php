<?php

namespace App;

enum ItemProductionStatus: string
{
    case Pending = 'Pending';
    case InProduction = 'In production';
    case ReadyForDelivery = 'Ready for delivery';
}
