<?php
namespace AppBundle\Controller;

use Biz\File\Service\UploadFileService;
use Biz\Task\Service\TaskService;
use Biz\Course\Service\CourseService;
use Biz\Task\Service\TaskResultService;
use Biz\Activity\Service\ActivityService;
use Biz\User\Service\TokenService;
use Biz\Util\CloudClientFactory;
use Symfony\Component\HttpFoundation\Request;

class TaskController extends BaseController
{
    public function showAction(Request $request, $courseId, $id)
    {
        $preview = $request->query->get('preview');
        $task    = $this->tryLearnTask($courseId, $id, (bool)$preview);

        $activity = $this->getActivityService()->getActivity($task['activityId']);
        if (empty($activity)) {
            throw $this->createNotFoundException("activity not found");
        }

        $this->getActivityService()->trigger($activity['id'], 'start', array(
            'task' => $task
        ));

        $course = $this->getCourseService()->getCourse($courseId);

        $taskResult = $this->getTaskResultService()->getUserTaskResultByTaskId($id);
        if ($taskResult['status'] == 'finish') {
            list($course, $nextTask, $finishedRate) = $this->getNextTaskAndFinishedRate($task);
        }

        return $this->render('task/show.html.twig', array(
            'course'       => $course,
            'task'         => $task,
            'taskResult'   => $taskResult,
            'activity'     => $activity,
            'preview'      => $preview,
            'nextTask'     => empty($nextTask) ? array() : $nextTask,
            'finishedRate' => empty($finishedRate) ? 0 : $finishedRate
        ));
    }

    public function previewAction(Request $request, $courseId, $id)
    {
        $course = $this->getCourseService()->getCourse($courseId);

        $task = $this->getTaskService()->getTask($id);

        $user = $this->getCurrentUser();

        if (empty($task) || $task['courseId'] != $courseId) {
            return $this->createNotFoundException('task is not exist');
        }

        //课程不可购买，且任务不免费
        if (empty($task['isFree']) && empty($course['buyable']) && empty($course['tryLookable'])) {
            return $this->render('task/preview-notice-modal.html.twig', array('course' => $course));
        }
        //课程关闭
        if (!empty($course['status']) && $course['status'] == 'closed') {
            return $this->render('task/preview-notice-modal.html.twig', array('course' => $course));
        }

        //课时不免费并且不满足1.有时间限制设置2.课时为视频课时3.视频课时非优酷等外链视频时提示购买
        if (empty($task['isFree']) && !(!empty($course['tryLookable']) && $task['type'] == 'video' && $task['mediaSource'] == 'self')) {
            if (!$user->isLogin()) {
                throw $this->createAccessDeniedException();
            }

            if ($course["parentId"] > 0) {
                //TODO 复制课程的预览逻辑
                //return $this->redirect($this->generateUrl('classroom_buy_hint', array('courseId' => $course["id"])));
            }

            return $this->forward('TopxiaWebBundle:CourseOrder:buy', array('id' => $courseId), array('preview' => true, 'lessonId' => $task['id']));
        }

        //在可预览情况下查看网站设置是否可匿名预览
        $allowAnonymousPreview = $this->setting('course.allowAnonymousPreview', 1);

        if (empty($allowAnonymousPreview) && !$user->isLogin()) {
            throw $this->createAccessDeniedException();
        }

        //TODO vip 插件改造 判断用户是否为VIP

        return $this->render('task/preview.html.twig', array(
            'course' => $course,
            'task'   => $task,
            'user'   => $user
        ));
    }

    public function qrcodeAction(Request $request, $courseId, $id)
    {
        $user = $this->getCurrentUser();
        $host = $request->getSchemeAndHttpHost();

        //TODO 移动端学习 api 重构
        if ($user->isLogin()) {
            $appUrl = "{$host}/mapi_v2/mobile/main#/lesson/{$courseId}/{$id}";
        } else {
            $appUrl = "{$host}/mapi_v2/mobile/main#/course/{$courseId}";
        }

        $token = $this->getTokenService()->makeToken('qrcode', array(
            'userId'   => $user['id'],
            'data'     => array(
                'url'    => $this->generateUrl('course_task_show', array('courseId' => $courseId, 'id' => $id), true),
                'appUrl' => $appUrl
            ),
            'times'    => 1,
            'duration' => 3600
        ));
        $url   = $this->generateUrl('common_parse_qrcode', array('token' => $token['token']), true);

        $response = array(
            'img' => $this->generateUrl('common_qrcode', array('text' => $url), true)
        );
        return $this->createJsonResponse($response);
    }


    public function taskActivityAction(Request $request, $courseId, $id)
    {
        $preview = $request->query->get('preview');
        $task    = $this->tryLearnTask($courseId, $id, $preview);

        if (empty($preview) && $task['status'] != 'published') {
            return $this->render('task/inform.html.twig');
        }
        return $this->forward('AppBundle:Activity/Activity:show', array(
            'id'       => $task['activityId'],
            'courseId' => $courseId
        ));
    }

    public function taskPluginsAction(Request $request, $courseId, $taskId)
    {
        $preview = $request->query->get('preview', false);

        $task = $this->tryLearnTask($courseId, $taskId);
        return $this->createJsonResponse(array(
            array(
                'code' => 'task-list',
                'name' => '课程',
                'icon' => 'es-icon-menu',
                'url'  => $this->generateUrl('course_task_show_plugin_task_list', array(
                    'courseId' => $courseId,
                    'taskId'   => $taskId,
                    'preview'  => $preview
                ))
            ),
            array(
                'code' => 'note',
                'name' => '笔记',
                'icon' => 'es-icon-edit',
                'url'  => $this->generateUrl('course_task_plugin_note', array(
                    'courseId' => $courseId,
                    'taskId'   => $taskId
                ))
            ),
            array(
                'code' => 'question',
                'name' => '问答',
                'icon' => 'es-icon-help',
                'url'  => $this->generateUrl('course_task_plugin_threads', array(
                    'courseId' => $courseId,
                    'taskId'   => $taskId
                ))
            )
        ));
    }

    public function triggerAction(Request $request, $courseId, $id)
    {
        $this->getCourseService()->tryTakeCourse($courseId);

        $eventName = $request->request->get('eventName');
        if (empty($eventName)) {
            throw $this->createNotFoundException('task event is empty');
        }

        $data   = $request->request->get('data', array());
        $result = $this->getTaskService()->trigger($id, $eventName, $data);

        return $this->createJsonResponse(array(
            'event'  => $eventName,
            'data'   => $data,
            'result' => $result
        ));
    }

    public function finishAction(Request $request, $courseId, $id)
    {
        $result = $this->getTaskService()->finishTask($id);
        $task   = $this->getTaskService()->getTask($id);
        list($course, $nextTask, $finishedRate) = $this->getNextTaskAndFinishedRate($task);

        return $this->render('task/finish-result.html.twig', array(
            'result'       => $result,
            'task'         => $task,
            'nextTask'     => $nextTask,
            'course'       => $course,
            'finishedRate' => $finishedRate
        ));
    }

    protected function getNextTaskAndFinishedRate($task)
    {
        $nextTask   = $this->getTaskService()->getNextTask($task['id']);
        $course     = $this->getCourseService()->getCourse($task['courseId']);
        $user       = $this->getUser();
        $conditions = array(
            'courseId' => $task['courseId'],
            'userId'   => $user['id'],
            'status'   => 'finish'
        );

        $finishedCount = $this->getTaskResultService()->countTaskResult($conditions);

        $finishedRate = empty($course['taskNum']) ? 0 : intval($finishedCount / $course['taskNum'] * 100);
        return array($course, $nextTask, $finishedRate);
    }

    protected function tryLearnTask($courseId, $taskId, $preview = false)
    {
        list($course, $member) = $this->getCourseService()->tryTakeCourse($courseId);
        if ($preview) {
            //TODO先注释掉这段代码，学员的逻辑现在有问题，无法判断是否老师，完善后在开发
//            if ($member['role'] != 'teacher' || $course['status'] != 'published') {
//                throw $this->createAccessDeniedException('you are  not allowed to learn the task ');
//            }
            $task = $this->getTaskService()->getTask($taskId);
        } else {
            $task = $this->getTaskService()->tryTakeTask($taskId);
        }

        if (empty($task)) {
            throw $this->createResourceNotFoundException('task', $taskId);
        }

        if ($task['courseId'] != $courseId) {
            throw $this->createAccessDeniedException();
        }
        return $task;
    }

    /**
     * @return CourseService
     */
    protected function getCourseService()
    {
        return $this->createService('Course:CourseService');
    }

    /**
     * @return TaskService
     */
    protected function getTaskService()
    {
        return $this->createService('Task:TaskService');
    }

    /**
     * @return TaskResultService
     */
    protected function getTaskResultService()
    {
        return $this->createService('Task:TaskResultService');
    }

    /**
     * @return ActivityService
     */
    protected function getActivityService()
    {
        return $this->createService('Activity:ActivityService');
    }

    protected function getCourseMemberService()
    {
        return $this->createService('Course:MemberService');
    }

    /**
     * @return UploadFileService
     */
    protected function getUploadFileService()
    {
        return $this->createService('File:UploadFileService');
    }

    /**
     * @return TokenService
     */
    protected function getTokenService()
    {
        return $this->createService('User:TokenService');
    }
}
