<?php

namespace Biz\OrderFacade\Product;

use Biz\Accessor\AccessorInterface;
use Biz\Classroom\Service\ClassroomService;
use Biz\OrderFacade\Exception\OrderPayCheckException;
use Codeages\Biz\Framework\Order\Status\OrderStatusCallback;

class ClassroomProduct extends Product implements OrderStatusCallback
{
    const TYPE = 'classroom';

    public $targetType = self::TYPE;

    public $showTemplate = 'order/show/classroom-item.html.twig';

    public function init(array $params)
    {
        $classroom = $this->getClassroomService()->getClassroom($params['targetId']);

        $this->targetId = $params['targetId'];
        $this->backUrl = array('routing' => 'classroom_show', 'params' => array('id' => $classroom['id']));
        $this->successUrl = array('classroom_show', array('id' => $this->targetId));
        $this->title = $classroom['title'];
        $this->originPrice = $classroom['price'];
        $this->middlePicture = $classroom['middlePicture'];
        $this->maxRate = $classroom['maxRate'];
    }

    public function validate()
    {
        $access = $this->getClassroomService()->canJoinClassroom($this->targetId);

        if ($access['code'] !== AccessorInterface::SUCCESS) {
            throw new OrderPayCheckException($access['msg'], Product::PRODUCT_VALIDATE_FAIL);
        }
    }

    public function onPaid($orderItem)
    {
        $this->smsCallback($orderItem);

        $order = $this->getOrderService()->getOrder($orderItem['order_id']);
        $info = array(
            'orderId' => $order['id'],
            'note' => $order['created_reason'],
        );

        try {
            $isStudent = $this->getClassroomService()->isClassroomStudent($orderItem['target_id'], $orderItem['user_id']);
            if (!$isStudent) {
                $this->getClassroomService()->becomeStudent($orderItem['target_id'], $orderItem['user_id'], $info);
            }

            return OrderStatusCallback::SUCCESS;
        } catch (\Exception $e) {
            $this->getLogService()->error('order', 'classroom_callback', 'order.classroom_callback.fail',
                array('error' => $e->getMessage(), 'context' => $orderItem));

            return false;
        }
    }

    /**
     * @return ClassroomService
     */
    protected function getClassroomService()
    {
        return $this->biz->service('Classroom:ClassroomService');
    }
}
