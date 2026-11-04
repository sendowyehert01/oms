<?php

namespace App;

enum OrderStatus: string
{
    case Pending = 'Pending';
    case InProduction = 'In production';
    case ReadyForDelivery = 'Ready for delivery';
    case Delivered = 'Delivered';
    case Completed = 'Completed';
}
