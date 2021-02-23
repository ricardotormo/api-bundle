<?php

namespace Webkul\UVDesk\ApiBundle\API;

use Webkul\TicketBundle\Entity\Ticket;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Webkul\UVDesk\CoreFrameworkBundle\Workflow\Events as CoreWorkflowEvents;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\Encoder\XmlEncoder;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;

class Tickets extends Controller
{
    /**
     * Return support tickets.
     *
     * @param Request $request
     */
    public function fetchTickets(Request $request)
    {
        $json = [];
        $entityManager = $this->getDoctrine()->getManager();
        $ticketRepository = $this->getDoctrine()->getRepository('UVDeskCoreFrameworkBundle:Ticket');
        $userRepository = $this->getDoctrine()->getRepository('UVDeskCoreFrameworkBundle:User');
        
        if ($request->query->get('actAsType')) {    
            switch($request->query->get('actAsType')) {
                case 'customer':
                    $email = $request->query->get('actAsEmail');
                    $customer = $entityManager->getRepository('UVDeskCoreFrameworkBundle:User')->findOneByEmail($email);

                    if ($customer) {
                        $json = $ticketRepository->getAllCustomerTickets($request->query, $this->container, $customer);
                    } else {
                        $json['error'] = $this->get('translator')->trans('Error! Resource not found.');
                        return new JsonResponse($json, Response::HTTP_NOT_FOUND);
                    }
                    return new JsonResponse($json);
                    break;
                case 'agent':
                    $user = $entityManager->getRepository('UVDeskCoreFrameworkBundle:User')->findOneByEmail($data['actAsEmail']);

                    if ($user) {
                        $request->query->set('agent', $user->getId());
                    } else {
                        $json['error'] = $this->get('translator')->trans('Error! Resource not found.');
                        return new JsonResponse($json, Response::HTTP_NOT_FOUND);
                    }
                    break;
                default:
                    $json['error'] = $this->get('translator')->trans('Error! invalid actAs details.');
                    return new JsonResponse($json, Response::HTTP_BAD_REQUEST);
            }
        }

        $json = $ticketRepository->getAllTickets($request->query, $this->container);

        $json['userDetails'] = [
            'user' => $this->getUser()->getId(),
            'name' => $this->getUser()->getFirstName().' '.$this->getUser()->getLastname(),
        ];

        $json['agents'] = $this->get('user.service')->getAgentsPartialDetails();
        $json['status'] = $this->get('ticket.service')->getStatus();
        $json['group'] = $userRepository->getSupportGroups(); 
        $json['team'] =  $userRepository->getSupportTeams();
        $json['priority'] = $this->get('ticket.service')->getPriorities();
        $json['type'] = $this->get('ticket.service')->getTypes();
        $json['source'] = $this->get('ticket.service')->getAllSources();

        return new JsonResponse($json);
    }

    /**
     * Return support tickets metadata.
     *
     * @param Request $request
     */
    public function fetchTicketsMetadata(Request $request) 
    {
        return new JsonResponse([]);
    }

    /**
     * Trash support tickets.
     *
     * @param Request $request
     * @return void
     */
    public function trashTicket(Request $request)
    {
        $ticketId = $request->attributes->get('ticketId');
        $entityManager = $this->getDoctrine()->getManager();
        $ticket = $entityManager->getRepository('UVDeskCoreFrameworkBundle:Ticket')->find($ticketId);
        
        if (!$ticket) {
            $this->noResultFound();
        }

        if (!$ticket->getIsTrashed()) {
            $ticket->setIsTrashed(1);
            $entityManager->persist($ticket);
            $entityManager->flush();

            $json['success'] = $this->get('translator')->trans('Success ! Ticket moved to trash successfully.');
            $statusCode = Response::HTTP_OK;

            // Trigger ticket delete event
            $event = new GenericEvent(CoreWorkflowEvents\Ticket\Delete::getId(), [
                'entity' => $ticket,
            ]);

            $this->get('event_dispatcher')->dispatch('uvdesk.automation.workflow.execute', $event);
        } else {
            $json['error'] = $this->get('translator')->trans('Warning ! Ticket is already in trash.');
            $statusCode = Response::HTTP_BAD_REQUEST;
        }

        return new JsonResponse($json, $statusCode);
    }

    /**
     * Create support tickets.
     *
     * @param Request $request
     * @return void
     */
    public function createTicket(Request $request)
    {
        $data = $request->request->all()? : json_decode($request->getContent(),true);
        foreach($data as $key => $value) {
            if(!in_array($key, ['subject', 'group', 'type', 'status','locale','domain', 'priority', 'agent', 'replies', 'createdAt', 'updatedAt', 'customFields', 'files', 'from', 'name', 'message', 'tags', 'actAsType', 'actAsEmail'])) {
                unset($data[$key]);
            }
        }
  
        if(!(isset($data['from']) && isset($data['name']) && isset($data['subject']) && isset($data['message']) &&  isset($data['actAsType']) || isset($data['actAsEmail']) )) {
            $json['error'] = $this->get('translator')->trans('required fields: name ,from, message, actAsType or actAsEmail');
            return new JsonResponse($json, Response::HTTP_BAD_REQUEST);
        }

        if($data) {
            $error = false;
            $message = '';
            $entityManager = $this->getDoctrine()->getManager();

            if ($data['subject'] == '') {
                $message = $this->get('translator')->trans("Warning! Please complete subject field value!");
                $statusCode = Response::HTTP_BAD_REQUEST;
            } elseif($data['message'] == '') {
                $json['message'] = $this->get('translator')->trans("Warning! Please complete message field value!");
                $statusCode = Response::HTTP_BAD_REQUEST;
            } elseif(filter_var($data['from'], FILTER_VALIDATE_EMAIL) === false) {
                $json['message'] = $this->get('translator')->trans("Warning! Invalid from Email Address!");
                $statusCode = Response::HTTP_BAD_REQUEST;
            }
            elseif ($data['actAsType'] == ''  &&  $data['actAsEmail'] == '') {
                $json['message'] = $this->get('translator')->trans("Warning! Provide atleast one parameter actAsType(agent or customer) or actAsEmail");
                $statusCode = Response::HTTP_BAD_REQUEST;
            }
            
            if (!$error) {
                $name = explode(' ',$data['name']);
                $ticketData['firstName'] = $name[0];
                $ticketData['lastName'] = isset($name[1]) ? $name[1] : '';
                $ticketData['role'] = 4;
             
                if ((array_key_exists('actAsType', $data)) && strtolower($data['actAsType']) == 'customer') {
                    $actAsType = strtolower($data['actAsType']);             
                } else if((array_key_exists('actAsEmail', $data)) && strtolower($data['actAsType']) == 'agent') {
                    $user = $entityManager->getRepository('UVDeskCoreFrameworkBundle:User')->findOneByEmail($data['actAsEmail']);
                    
                    if ($user) {
                        $actAsType = 'agent';
                    } else {
                        $json['error'] = $this->get('translator')->trans("Error ! actAsEmail is not valid");
                        return new JsonResponse($json, Response::HTTP_BAD_REQUEST);
                    }
                } else {
                    $json['warning'] = $this->get('translator')->trans('Warning ! For Customer specify actAsType as customer and for Agent specify both parameter actASType  as agent and actAsEmail as agent email');
                    $statusCode = Response::HTTP_BAD_REQUEST;
                    return new JsonResponse($json, $statusCode);
                }
                
                // Create customer if account does not exists
                $customer = $entityManager->getRepository('UVDeskCoreFrameworkBundle:User')->findOneByEmail($data['from']);
             
                if (empty($customer) || null == $customer->getCustomerInstance()) {
                    $role = $entityManager->getRepository('UVDeskCoreFrameworkBundle:SupportRole')->findOneByCode('ROLE_CUSTOMER');
                  
                    // Create User Instance
                    $customer = $this->get('user.service')->createUserInstance($data['from'], $data['name'], $role, [
                        'source' => 'api',
                        'active' => true
                    ]);
                }

                if ($actAsType == 'agent') {
                    $data['user'] = isset($user) && $user ? $user : $this->get('user.service')->getCurrentUser();
                } else {
                    $data['user'] = $customer;
                }

                $attachments = $request->files->get('attachments');
                if (!empty($attachments)) {
                        $attachments = is_array($attachments) ? $attachments : [$attachments];
                }
                
                $ticketData['user'] = $data['user'];
                $ticketData['subject'] = $data['subject'];
                $ticketData['message'] = $data['message'];
                $ticketData['customer'] = $customer;
                $ticketData['source'] = 'api';
                $ticketData['threadType'] = 'create';
                $ticketData['createdBy'] = $actAsType;
                $ticketData['attachments'] = $attachments;
                
                $extraKeys = ['tags', 'group', 'priority', 'status', 'agent', 'createdAt', 'updatedAt'];

                if (array_key_exists('type', $data)) {
                    $ticketType = $entityManager->getRepository('UVDeskCoreFrameworkBundle:TicketType')->findOneByCode($data['type']);
                    $ticketData['type'] = $ticketType;
                }
                
                $requestData = $data;
                foreach ($extraKeys as $key) {
                    if (isset($ticketData[$key])) {
                        unset($ticketData[$key]);
                    }
                }
                
                $thread = $this->get('ticket.service')->createTicketBase($ticketData);
                // Trigger ticket created event
                try {
                    $event = new GenericEvent(CoreWorkflowEvents\Ticket\Create::getId(), [
                        'entity' =>  $thread->getTicket(),
                    ]);
                    $this->get('event_dispatcher')->dispatch('uvdesk.automation.workflow.execute', $event);
                } catch (\Exception $e) {
                    //
                }

                $json['message'] = $this->get('translator')->trans('Success ! Ticket has been created successfully.');
                $json['ticketId'] = $thread->getTicket()->getId();
                $statusCode = Response::HTTP_OK;

            } else {
                $json['message'] = $this->get('translator')->trans('Warning ! Required parameters should not be blank');
                $statusCode = Response::HTTP_BAD_REQUEST;
            }
        } else {
            $json['error'] = $this->get('translator')->trans('invalid/empty size of Request');
            $json['message'] = $this->get('translator')->trans('Warning ! Post size can not exceed 25MB');
            $statusCode = Response::HTTP_BAD_REQUEST;
        }

        return new JsonResponse($json, $statusCode);
    }

    /**
     * View support tickets.
     *
     * @param Request $request
     * @return void
     */
    public function viewTicket($ticketId, Request $request)
    {
        $entityManager = $this->getDoctrine()->getManager();
        $userRepository = $entityManager->getRepository('UVDeskCoreFrameworkBundle:User');
        $ticketRepository = $entityManager->getRepository('UVDeskCoreFrameworkBundle:Ticket');

        $ticket = $ticketRepository->findOneById($ticketId);

        if (empty($ticket)) {
            throw new \Exception('Page not found');
        }

        $agent = $ticket->getAgent();
        $customer = $ticket->getCustomer();

        // Mark as viewed by agents
        if (false == $ticket->getIsAgentViewed()) {
            $ticket->setIsAgentViewed(true);

            $entityManager->persist($ticket);
            $entityManager->flush();
        }

        // Ticket status Collection
        $status = array_map(function ($statusCollection) {
            return [
                'id' => $statusCollection->getId(),
                'code' => $statusCollection->getCode(),
                'colorCode' => $statusCollection->getColorCode(),
                'description' => $statusCollection->getDescription(),
            ];
        }, $entityManager->getRepository('UVDeskCoreFrameworkBundle:TicketStatus')->findAll());

        // Ticket Type Collection
        $type = array_map(function ($ticketTypeCollection) {
            return [
                'id' => $ticketTypeCollection->getId(),
                'code' => $ticketTypeCollection->getCode(),
                'isActive' => $ticketTypeCollection->getIsActive(),
                'description' => $ticketTypeCollection->getDescription(),
            ];
        }, $entityManager->getRepository('UVDeskCoreFrameworkBundle:TicketType')->findByIsActive(true));

        // Priority Collection
        $priority = array_map(function ($ticketPriorityCollection) {
            return [
                'id' => $ticketPriorityCollection->getId(),
                'code' => $ticketPriorityCollection->getCode(),
                'colorCode' => $ticketPriorityCollection->getColorCode(),
                'description' => $ticketPriorityCollection->getDescription(),
            ];
        }, $entityManager->getRepository('UVDeskCoreFrameworkBundle:TicketPriority')->findAll());
      
        $ticketObj = $ticket;
        $ticket = json_decode($this->objectSerializer($ticketObj), true);
      
        return new JsonResponse([
            'ticket' => $ticket,
            'totalCustomerTickets' => ($ticketRepository->countCustomerTotalTickets($customer)),
            'ticketAgent' => !empty($agent) ? $agent->getAgentInstance()->getPartialDetails() : null,
            'customer' => $customer->getCustomerInstance()->getPartialDetails(),
            'supportGroupCollection' => $userRepository->getSupportGroups(),
            'supportTeamCollection' => $userRepository->getSupportTeams(),
            'ticketStatusCollection' => $status,
            'ticketPriorityCollection' => $priority,
            'ticketTypeCollection' => $type
        ]);
    }

    /**
     * delete support tickets.
     *
     * @param Request $request
     * @return void
     */
    public function deleteTicketForever(Request $request)
    {
        $ticketId = $request->attributes->get('ticketId');
        $entityManager = $this->getDoctrine()->getManager();
        $ticket = $entityManager->getRepository('UVDeskCoreFrameworkBundle:Ticket')->find($ticketId);
        
        if (!$ticket) {
            $this->noResultFound();
        }

        if ($ticket->getIsTrashed()) {
            $entityManager->remove($ticket);
            $entityManager->flush();

            $json['success'] = $this->get('translator')->trans('Success ! Ticket removed successfully.');
            $statusCode = Response::HTTP_OK;

            // Trigger ticket delete event
            $event = new GenericEvent(CoreWorkflowEvents\Ticket\Delete::getId(), [
                'entity' => $ticket,
            ]);

            $this->get('event_dispatcher')->dispatch('uvdesk.automation.workflow.execute', $event);
        } else {
            $json['error'] = $this->get('translator')->trans('Warning ! something went wrong.');
            $statusCode = Response::HTTP_BAD_REQUEST;
        }

        return new JsonResponse($json, $statusCode);
    }

    /**
     * Assign Ticket to a agent
     *
     * @param Request $request
     * @return void
    */
    public function assignAgent(Request $request)
    {
        $json = [];
        $data = $request->request->all() ? :json_decode($request->getContent(), true);
        $ticketId = $request->attributes->get('ticketId');
        $entityManager = $this->getDoctrine()->getManager();
        $ticket = $entityManager->getRepository('UVDeskCoreFrameworkBundle:Ticket')->findOneBy(array('id' => $ticketId));
    
        if ($ticket) {
            if (isset($data['id'])) {
                $agent = $entityManager->getRepository('UVDeskCoreFrameworkBundle:User')->find($data['id']);
            } else {
                $json['error'] = $this->get('translator')->trans('missing fields');   
                $json['description'] = $this->get('translator')->trans('required: id ');     
                return new JsonResponse($json, Response::HTTP_BAD_REQUEST);   
            }
           
            if ($agent) {
                if($ticket->getAgent() != $agent) {
                    $ticket->setAgent($agent);
                    $entityManager->persist($ticket);
                    $entityManager->flush();

                    $json['success'] = $this->get('translator')->trans('Success ! Ticket assigned to agent successfully.');
                    $statusCode = Response::HTTP_OK;
        
                    // Trigger ticket delete event
                    $event = new GenericEvent(CoreWorkflowEvents\Ticket\Agent::getId(), [
                        'entity' => $ticket,
                    ]);
        
                    $this->get('event_dispatcher')->dispatch('uvdesk.automation.workflow.execute', $event);
                    
                } else {
                    $json['error'] = $this->get('translator')->trans('invalid resource');
                    $json['description'] = $this->get('translator')->trans('Error ! Invalid agent or already assigned for this ticket');
                    $statusCode = Response::HTTP_NOT_FOUND;
                }
            }
        } else {
            $json['error'] = $this->get('translator')->trans('invalid ticket');
            $statusCode = Response::HTTP_NOT_FOUND;
        }

        return new JsonResponse($json, $statusCode);  
    }

    /**
     * adding  or removing collaborator to a Ticket
     *
     * @param Request $request
     * @return void
    */

    public function addRemoveTicketCollaborator(Request $request) 
    {
        $json = [];
        $statusCode = Response::HTTP_OK;
        $content = $request->request->all()? : json_decode($request->getContent(), true);

        $entityManager = $this->getDoctrine()->getManager();
        $ticket = $entityManager->getRepository('UVDeskCoreFrameworkBundle:Ticket')->find($request->attributes->get('ticketId'));
        if(!$ticket) {
            $json['error'] =  $this->get('translator')->trans('resource not found');
            return new JsonResponse($json, Response::HTTP_NOT_FOUND);
        }

        if($request->getMethod() == "POST") { 
            if(!isset($content['email']) || !filter_var($content['email'], FILTER_VALIDATE_EMAIL)) {
                $json['error'] = $this->get('translator')->trans('missing/invalid field');
                $json['message'] = $this->get('translator')->trans('required: email');
                return new JsonResponse($json, Response::HTTP_BAD_REQUEST);
            }

            if($content['email'] == $ticket->getCustomer()->getEmail()) {
                $json['error'] = $this->get('translator')->trans('Error ! Can not add customer as a collaborator.');
                $statusCode = Response::HTTP_BAD_REQUEST;
            } else {
                $data = array(
                    'from' => $content['email'],
                    'firstName' => ($firstName = ucfirst(current(explode('@', $content['email'])))),
                    'lastName' => ' ',
                    'role' => 4,
                );
                
                $supportRole = $entityManager->getRepository('UVDeskCoreFrameworkBundle:SupportRole')->findOneByCode('ROLE_CUSTOMER');
                $collaborator = $this->get('user.service')->createUserInstance($data['from'], $data['firstName'], $supportRole, $extras = ["active" => true]);
                $checkTicket = $entityManager->getRepository('UVDeskCoreFrameworkBundle:Ticket')->isTicketCollaborator($ticket, $content['email']);

                if (!$checkTicket) { 
                    $ticket->addCollaborator($collaborator);
                    $entityManager->persist($ticket);
                    $entityManager->flush();

                    $ticket->lastCollaborator = $collaborator;

                    if ($collaborator->getCustomerInstance())
                        $json['collaborator'] = $collaborator->getCustomerInstance()->getPartialDetails();
                    else
                        $json['collaborator'] = $collaborator->getAgentInstance()->getPartialDetails();

                    $event = new GenericEvent(CoreWorkflowEvents\Ticket\Collaborator::getId(), [
                        'entity' => $ticket,
                    ]);

                    $this->get('event_dispatcher')->dispatch('uvdesk.automation.workflow.execute', $event);

                    $json['success'] =  $this->get('translator')->trans('Success ! Collaborator added successfully.');
                    $statusCode = Response::HTTP_OK;
                } else {
                    $json['warning'] =  $this->get('translator')->trans('Collaborator is already added.');
                    $statusCode = Response::HTTP_BAD_REQUEST;
                }
            }
        } elseif($request->getMethod() == "DELETE") {
            $collaborator = $entityManager->getRepository('UVDeskCoreFrameworkBundle:User')->findOneBy(array('id' => $content['id']));
            if($collaborator) {
                $ticket->removeCollaborator($collaborator);
                $entityManager->persist($ticket);
                $entityManager->flush();

                $json['success'] =  $this->get('translator')->trans('Success ! Collaborator removed successfully.');
                $statusCode = Response::HTTP_OK;
            } else {
                $json['error'] =  $this->get('translator')->trans('Error ! Invalid Collaborator.');
                $statusCode = Response::HTTP_BAD_REQUEST;
            }
        }

        return new JsonResponse($json, $statusCode);  
    }
    
    /**
     * Download ticket attachment
     *
     * @param Request $request
     * @return void
    */
    public function downloadAttachment(Request $request) 
    {
        $attachmentId = $request->attributes->get('attachmentId');
        $attachmentRepository = $this->getDoctrine()->getManager()->getRepository('UVDeskCoreFrameworkBundle:Attachment');
        $attachment = $attachmentRepository->findOneById($attachmentId);
        $baseurl = $request->getScheme() . '://' . $request->getHttpHost() . $request->getBasePath();

        if (!$attachment) {
            $this->noResultFound();
        }

        $path = $this->get('kernel')->getProjectDir() . "/public/". $attachment->getPath();

        $response = new Response();
        $response->setStatusCode(200);

        $response->headers->set('Content-type', $attachment->getContentType());
        $response->headers->set('Content-Disposition', 'attachment; filename='. $attachment->getName());
        $response->sendHeaders();
        $response->setContent(readfile($path));

        return $response; 
    }

    /**
     * Download Zip attachment
     *
     * @param Request $request
     * @return void
    */
    public function downloadZipAttachment(Request $request)
    {
        $threadId = $request->attributes->get('threadId');
        $attachmentRepository = $this->getDoctrine()->getManager()->getRepository('UVDeskCoreFrameworkBundle:Attachment');

        $attachment = $attachmentRepository->findByThread($threadId);

        if (!$attachment) {
            $this->noResultFound();
        }

        $zipname = 'attachments/' .$threadId.'.zip';
        $zip = new \ZipArchive;

        $zip->open($zipname, \ZipArchive::CREATE);
        if (count($attachment)) {
            foreach ($attachment as $attach) {
                $zip->addFile(substr($attach->getPath(), 1));
            }
        }

        $zip->close();

        $response = new Response();
        $response->setStatusCode(200);
        $response->headers->set('Content-type', 'application/zip');
        $response->headers->set('Content-Disposition', 'attachment; filename=' . $threadId . '.zip');
        $response->sendHeaders();
        $response->setContent(readfile($zipname));

        return $response;
    }

    /**
     * Edit Ticket properties
     *
     * @param Request $request
     * @return void
    */
    public function editTicketProperties(Request $request) 
    {
        $json = [];
        $statusCode = Response::HTTP_OK;

        $entityManager = $this->getDoctrine()->getManager();
        $requestContent = $request->request->all() ?: json_decode($request->getContent(), true);
        $ticketId =  $request->attributes->get('ticketId');
        $ticket = $entityManager->getRepository('UVDeskCoreFrameworkBundle:Ticket')->findOneById($ticketId);
        // Validate request integrity
        if (empty($ticket)) {
            $json['error']  = 'invalid resource';
            $json['description'] =  $this->get('translator')->trans('Unable to retrieve details for ticket #%ticketId%.', [
                                        '%ticketId%' => $ticketId,
                                    ]);
            $statusCode = Response::HTTP_NOT_FOUND;
            return new JsonResponse($json, $statusCode);  
        } else if (!isset($requestContent['property'])) {
            $json['error']  =  $this->get('translator')->trans('missing resource');
            $json['description'] = $this->get('translator')->trans('Insufficient details provided.');
            $statusCode = Response::HTTP_BAD_REQUEST;
            return new JsonResponse($json, $statusCode); 
        }
        // Update property
        switch ($requestContent['property']) {
            case 'agent':
                $agent = $entityManager->getRepository('UVDeskCoreFrameworkBundle:User')->findOneById($requestContent['value']);
                if (empty($agent)) {
                    // User does not exist
                    $json['error']  = $this->get('translator')->trans('No such user exist');
                    $json['description'] = $this->get('translator')->trans('Unable to retrieve agent details');
                    $statusCode = Response::HTTP_BAD_REQUEST;
                    return new JsonResponse($json, $statusCode);
                } else {
                    // Check if an agent instance exists for the user
                    $agentInstance = $agent->getAgentInstance();
                    if (empty($agentInstance)) {
                        // Agent does not exist
                        $json['error']  = $this->get('translator')->trans('No such user exist');
                        $json['description'] = $this->get('translator')->trans('Unable to retrieve agent details');
                        $statusCode = Response::HTTP_BAD_REQUEST;
                        return new JsonResponse($json, $statusCode);
                    }
                }

                $agentDetails = $agentInstance->getPartialDetails();

                // Check if ticket is already assigned to the agent
                if ($ticket->getAgent() && $agent->getId() === $ticket->getAgent()->getId()) {
                    $json['success']  = $this->get('translator')->trans('Already assigned');
                    $json['description'] = $this->get('translator')->trans('Ticket already assigned to %agent%', [
                        '%agent%' => $agentDetails['name']]);
                    $statusCode = Response::HTTP_OK;
                    return new JsonResponse($json, $statusCode);
                } else {
                    $ticket->setAgent($agent);
                    $entityManager->persist($ticket);
                    $entityManager->flush();

                    // Trigger Agent Assign event
                    $event = new GenericEvent(CoreWorkflowEvents\Ticket\Agent::getId(), [
                        'entity' => $ticket,
                    ]);

                    $this->get('event_dispatcher')->dispatch('uvdesk.automation.workflow.execute', $event);

                    $json['success']  = $this->get('translator')->trans('Success');
                    $json['description'] = $this->get('translator')->trans('Ticket successfully assigned to %agent%', [
                        '%agent%' => $agentDetails['name'],
                    ]);
                    $statusCode = Response::HTTP_OK;
                    return new JsonResponse($json, $statusCode);
                }
                break;
            case 'status':
                $ticketStatus = $entityManager->getRepository('UVDeskCoreFrameworkBundle:TicketStatus')->findOneById((int) $requestContent['value']);

                if (empty($ticketStatus)) {
                    // Selected ticket status does not exist
                    $json['error']  = $this->get('translator')->trans('Error');
                    $json['description'] = $this->get('translator')->trans('Unable to retrieve status details');
                    $statusCode = Response::HTTP_BAD_REQUEST;
                    return new JsonResponse($json, $statusCode);
                }

                if ($ticketStatus->getId() === $ticket->getStatus()->getId()) {
                    $json['success']  = $this->get('translator')->trans('Success');
                    $json['description'] = $this->get('translator')->trans('Ticket status already set to %status%', [
                        '%status%' => $ticketStatus->getDescription()]);
                    $statusCode = Response::HTTP_OK;
                    return new JsonResponse($json, $statusCode);
                } else {
                    $ticket->setStatus($ticketStatus);

                    $entityManager->persist($ticket);
                    $entityManager->flush();

                    // Trigger ticket status event
                    $event = new GenericEvent(CoreWorkflowEvents\Ticket\Status::getId(), [
                        'entity' => $ticket,
                    ]);

                    $this->get('event_dispatcher')->dispatch('uvdesk.automation.workflow.execute', $event);

                    $json['success']  =  $this->get('translator')->trans('Success');
                    $json['description'] =  $this->get('translator')->trans('Ticket status update to %status%', [
                        '%status%' => $ticketStatus->getDescription()]);
                    $statusCode = Response::HTTP_OK;
                    return new JsonResponse($json, $statusCode);
                }
                break;
            case 'priority':
                // $this->isAuthorized('ROLE_AGENT_UPDATE_TICKET_PRIORITY');
                $ticketPriority = $entityManager->getRepository('UVDeskCoreFrameworkBundle:TicketPriority')->findOneById($requestContent['value']);

                if (empty($ticketPriority)) {
                    // Selected ticket priority does not exist
                    $json['error']  = $this->get('translator')->trans('Error');
                    $json['description'] =  $this->get('translator')->trans('Unable to retrieve priority details');
                    $statusCode = Response::HTTP_BAD_REQUEST;
                    return new JsonResponse($json, $statusCode);
                }

                if ($ticketPriority->getId() === $ticket->getPriority()->getId()) {
                    $json['success']  = $this->get('translator')->trans('Success');
                    $json['description'] =  $this->get('translator')->trans('Ticket priority already set to %priority%', [
                        '%priority%' => $ticketPriority->getDescription()
                    ]);
                    $statusCode = Response::HTTP_OK;
                    return new JsonResponse($json, $statusCode);
                } else {
                    $ticket->setPriority($ticketPriority);
                    $entityManager->persist($ticket);
                    $entityManager->flush();

                    // Trigger ticket Priority event
                    $event = new GenericEvent(CoreWorkflowEvents\Ticket\Priority::getId(), [
                        'entity' => $ticket,
                    ]);

                    $this->get('event_dispatcher')->dispatch('uvdesk.automation.workflow.execute', $event);

                    $json['success']  = $this->get('translator')->trans('Success');
                    $json['description'] =  $this->get('translator')->trans('Ticket priority updated to %priority%', [
                        '%priority%' => $ticketPriority->getDescription()
                    ]);
                    $statusCode = Response::HTTP_OK;
                    return new JsonResponse($json, $statusCode);
                }
                break;
            case 'group':
                $supportGroup = $entityManager->getRepository('UVDeskCoreFrameworkBundle:SupportGroup')->findOneById($requestContent['value']);

                if (empty($supportGroup)) {
                    if ($requestContent['value'] == "") {
                        if ($ticket->getSupportGroup() != null) {
                            $ticket->setSupportGroup(null);
                            $entityManager->persist($ticket);
                            $entityManager->flush();
                        }

                        $json['success']  = $this->get('translator')->trans('Success');
                        $json['description'] =   $this->get('translator')->trans('Ticket support group updated successfully');
                        $statusCode = Response::HTTP_OK;
                    } else {
                        $json['error']  = $this->get('translator')->trans('Error');
                        $json['description'] = $this->get('translator')->trans('Unable to retrieve support group details');
                        $statusCode = Response::HTTP_BAD_REQUEST;
                    }

                    return new JsonResponse($json, $statusCode);
                }

                if ($ticket->getSupportGroup() != null && $supportGroup->getId() === $ticket->getSupportGroup()->getId()) {
                    $json['success']  = $this->get('translator')->trans('Success');
                    $json['description'] = $this->get('translator')->trans('Ticket already assigned to support group');
                    $statusCode = Response::HTTP_OK;
                    return new JsonResponse($json, $statusCode);
                } else {
                    $ticket->setSupportGroup($supportGroup);
                    $entityManager->persist($ticket);
                    $entityManager->flush();

                    // Trigger Support group event
                    $event = new GenericEvent(CoreWorkflowEvents\Ticket\Group::getId(), [
                        'entity' => $ticket,
                    ]);

                    $this->get('event_dispatcher')->dispatch('uvdesk.automation.workflow.execute', $event);

                    $json['success']  = $this->get('translator')->trans('Success');
                    
                    $json['description'] = $this->get('translator')->trans('Ticket assigned to support group successfully');
                    $json['description'] = $this->get('translator')->trans('Ticket assigned to support group %group%', [
                        '%group%' => $supportGroup->getDescription()
                    ]);
                    
                    $statusCode = Response::HTTP_OK;
                    return new JsonResponse($json, $statusCode);
                }
                break;
            case 'team':
                $supportTeam = $entityManager->getRepository('UVDeskCoreFrameworkBundle:SupportTeam')->findOneById($requestContent['value']);

                if (empty($supportTeam)) {
                    if ($requestContent['value'] == "") {
                        if ($ticket->getSupportTeam() != null) {
                            $ticket->setSupportTeam(null);
                            $entityManager->persist($ticket);
                            $entityManager->flush();
                        }

                        $json['success']  = $this->get('translator')->trans('Success');
                        $json['description'] = $this->get('translator')->trans('Ticket support team updated successfully');
                        $statusCode = Response::HTTP_OK;
                        return new JsonResponse($json, $statusCode);
                    } else {
                        $json['error']  = $this->get('translator')->trans('Error');
                        $json['description'] = $this->get('translator')->trans('Unable to retrieve support team details');
                        $statusCode = Response::HTTP_BAD_REQUEST;
                        return new JsonResponse($json, $statusCode);
                    }
                }

                if ($ticket->getSupportTeam() != null && $supportTeam->getId() === $ticket->getSupportTeam()->getId()) {
                        $json['success']  = $this->get('translator')->trans('Success');
                        $json['description'] = $this->get('translator')->trans('Ticket already assigned to support team');
                        $statusCode = Response::HTTP_OK;
                        return new JsonResponse($json, $statusCode);
                } else {
                    $ticket->setSupportTeam($supportTeam);
                    $entityManager->persist($ticket);
                    $entityManager->flush();

                    // Trigger ticket delete event
                    $event = new GenericEvent(CoreWorkflowEvents\Ticket\Team::getId(), [
                        'entity' => $ticket,
                    ]);

                    $this->get('event_dispatcher')->dispatch('uvdesk.automation.workflow.execute', $event);

                    $json['success']  = $this->get('translator')->trans('Success');
                    $json['description'] = $this->get('translator')->trans('Ticket assigned to support team successfully');
                    $json['description'] = $this->get('translator')->trans('Ticket assigned to support team %team%', [
                        '%team%' => $supportTeam->getDescription()
                    ]);
                    $statusCode = Response::HTTP_OK;
                    return new JsonResponse($json, $statusCode);
                }
                break;
            case 'type':
                // $this->isAuthorized('ROLE_AGENT_UPDATE_TICKET_TYPE');
                $ticketType = $entityManager->getRepository('UVDeskCoreFrameworkBundle:TicketType')->findOneById($requestContent['value']);

                if (empty($ticketType)) {
                    // Selected ticket priority does not exist
                    $json['error']  = $this->get('translator')->trans('Error');
                    $json['description'] = $this->get('translator')->trans('Unable to retrieve ticket type details');
                    $statusCode = Response::HTTP_BAD_REQUEST;
                    return new JsonResponse($json, $statusCode);
                }

                if (null != $ticket->getType() && $ticketType->getId() === $ticket->getType()->getId()) {
                    $json['success']  = $this->get('translator')->trans('Success');
                    $json['description'] = $this->get('translator')->trans('Ticket type already set to ' . $ticketType->getDescription());
                    $statusCode = Response::HTTP_OK;
                    return new JsonResponse($json, $statusCode);
                } else {
                    $ticket->setType($ticketType);

                    $entityManager->persist($ticket);
                    $entityManager->flush();

                    // Trigger ticket delete event
                    $event = new GenericEvent(CoreWorkflowEvents\Ticket\Type::getId(), [
                        'entity' => $ticket,
                    ]);

                    $this->get('event_dispatcher')->dispatch('uvdesk.automation.workflow.execute', $event);

                    $json['success']  = $this->get('translator')->trans('Success');
                    $json['description'] = $this->get('translator')->trans('Ticket type updated to ' . $ticketType->getDescription());
                    $statusCode = Response::HTTP_OK;
                    return new JsonResponse($json, $statusCode);
                }
                break;
            case 'label':
                $label = $entityManager->getRepository('UVDeskCoreFrameworkBundle:SupportLabel')->find($requestContent['value']);
                if ($label) {
                    $ticket->removeSupportLabel($label);
                    $entityManager->persist($ticket);
                    $entityManager->flush();

                    $json['success']  = $this->get('translator')->trans('Success');
                    $json['description'] = $this->get('translator')->trans('Success ! Ticket to label removed successfully');
                    $statusCode = Response::HTTP_OK;
                    return new JsonResponse($json, $statusCode);
                } else {
                    $json['error']  = $this->get('translator')->trans('Error');
                    $json['description'] = $this->get('translator')->trans('No support level exist for this ticket with this id');
                    $statusCode = Response::HTTP_BAD_REQUEST;
                    return new JsonResponse($json, $statusCode);
                }
                break;
            default:
                break;
        }

        return new JsonResponse($json, $statusCode);  
    }

    /**
     * objectSerializer This function convert Entity object into json contenxt
     * @param Object $object Customer Entity object
     * @return JSON  JSON context
     */
    public function objectSerializer($object) {
        $object->formatedCreatedAt = new \Datetime;
        $encoders = array(new XmlEncoder(), new JsonEncoder());
        $normalizer = new ObjectNormalizer();
        $normalizer->setCircularReferenceHandler(function ($object) {
            return $object->getId();
        });

        $normalizers = array($normalizer);
        $serializer = new Serializer($normalizers, $encoders);
        $jsonContent = $serializer->serialize($object, 'json');

        return $jsonContent;
    }
}