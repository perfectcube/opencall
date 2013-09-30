<?php

namespace OnCall\Bundle\AdminBundle\Controller;

use OnCall\Bundle\AdminBundle\Model\Controller;
use OnCall\Bundle\AdminBundle\Model\MenuHandler;
use Symfony\Component\HttpFoundation\Response;
use OnCall\Bundle\AdminBundle\Entity\Number;
use OnCall\Bundle\AdminBundle\Entity\Client;
use OnCall\Bundle\AdminBundle\Model\NumberType;
use Doctrine\DBAL\DBALException;

class NumberController extends Controller
{
    public function indexAction()
    {
        $em = $this->getDoctrine()->getManager();
        $req = $this->getRequest();

        // TODO: create method in custom repo
        // get clients, eager load users
        $dql = 'select c,u from OnCall\Bundle\AdminBundle\Entity\Client c join c.user u order by u.business_name asc, c.name asc';
        $cl_query = $em->createQuery($dql);
        $clients = $cl_query->getResult();

        // get numbers
        $repo = $this->getDoctrine()->getRepository('OnCallAdminBundle:Number');
        $num_query = $repo->createQueryBuilder('n');
        
        $type = $req->get('type');
        $usage = $req->get('usage');
        
        // get types
        $types = NumberType::getAll();

        // usage filter
        if ($usage === '1')
            $num_query->where('n.client is not null');
        else if ($usage === '0')
            $num_query->where('n.client is null');

        // type filter
        if ($type != null && $type !== '')
        {
            $num_query->andWhere('n.type = :type')
                ->setParameter('type', $type);
        }

        // sort by
        $num_query->orderBy('n.id', 'asc');

        // actual query
        $numbers = $num_query->getQuery()->getResult();

        // get role hash for menu
        $user = $this->getUser();
        $role_hash = $user->getRoleHash();

        return $this->render(
            'OnCallAdminBundle:Number:index.html.twig',
            array(
                'sidebar_menu' => MenuHandler::getMenu($role_hash, 'number'),
                'clients' => $clients,
                'numbers' => $numbers,
                'types' => $types,
                'type' => $type,
                'usage' => $usage
            )
        );
    }

    protected function updateNumber(Number $num, $data)
    {
        // TODO: cleanup parameters / default value
        $provider = trim($data['provider']);
        $type = $data['type'];
        $price_buy = $data['price_buy'];
        $price_resale = $data['price_resale'];

        $num->setProvider($provider)
            ->setType($type)
            ->setPriceBuy($price_buy)
            ->setPriceResale($price_resale);
    }

    public function createMultipleAction()
    {
        $data = $this->getRequest()->request->all();
        $em = $this->getDoctrine()->getManager();

        // get numbers
        $numbers = explode("\n", $data['numbers']);
        $nlen = count($numbers);

        // trim numbers
        for ($i = 0; $i < $nlen; $i++)
            $numbers[$i] = trim($numbers[$i]);


        // create the numbers
        try
        {
            foreach ($numbers as $num_text)
            {
                $num = new Number($num_text);
                $this->updateNumber($num, $data);
                $em->persist($num);
            }

            $this->addFlash('success', 'Numbers have been added to the pool.');

            $em->flush();
        }
        catch (DBALException $e)
        {
            $this->addFlash('error', 'One or more of the numbers already exist and cannot be added.');
        }

        return $this->redirect($this->generateUrl('oncall_admin_numbers'));
    }

    public function getAction($id)
    {
        $repo = $this->getDoctrine()->getRepository('OnCallAdminBundle:Number');
        $num = $repo->find($id);

        // not found
        if ($num == null)
            return new Response('');

        return new Response($num->jsonify());
    }

    public function updateAction($id)
    {
        $data = $this->getRequest()->request->all();
        $em = $this->getDoctrine()->getManager();

        // find
        $repo = $this->getDoctrine()->getRepository('OnCallAdminBundle:Number');
        $num = $repo->find($id);

        // not found
        if ($num == null)
        {
            $this->addFlash('error', 'Number could not be found.');
            return $this->redirect($this->generateUrl('oncall_admin_numbers'));
        }

        // update
        $this->updateNumber($num, $data);
        $em->flush();
        $this->addFlash('success', 'Number information updated.');

        return $this->redirect($this->generateUrl('oncall_admin_numbers'));
    }

    public function assignAction($client_id)
    {
        $em = $this->getDoctrine()->getManager();

        // find client
        $client = $this->getDoctrine()
            ->getRepository('OnCallAdminBundle:Client')
            ->find($client_id);

        // no client found
        if ($client == null)
        {
            $this->addFlash('error', 'Could not find client.');
            return $this->redirect($this->generateUrl('oncall_admin_numbers'));
        }

        // get the numbers
        $num_ids = $this->getRequest()->request->get('number_ids');
        if ($num_ids == null || !is_array($num_ids))
        {
            $this->addFlash('error', 'No numbers to assign.');
            return $this->redirect($this->generateUrl('oncall_admin_numbers'));
        }

        // iterate through all numbers checked
        foreach ($num_ids as $num)
        {
            // find number
            $num_object = $this->getDoctrine()
                ->getRepository('OnCallAdminBundle:Number')
                ->find($num);
            if ($num_object == null)
            {
                continue;
            }

            // TODO: check if we can assign

            // TODO: log number assignment

            // assign
            $num_object->setClient($client);
        }

        // flush db
        $em->flush();

        $this->addFlash('success', 'The numbers have been assigned.');

        return $this->redirect($this->generateUrl('oncall_admin_numbers'));
    }

    public function deleteAction($id)
    {
        $em = $this->getDoctrine()->getManager();

        // find
        $repo = $this->getDoctrine()->getRepository('OnCallAdminBundle:Number');
        $num = $repo->find($id);
        if ($num == null)
        {
            $this->addFlash('error', 'Number could not be found');
            return $this->redirect($this->generateUrl('oncall_admin_numbers'));
        }

        // check if we can delete
        if ($num->isInUse())
        {
            $this->addFlash('error', 'Could not delete number, it is in use.');
            return $this->redirect($this->generateUrl('oncall_admin_numbers'));
        }

        // delete
        $em->remove($num);
        $em->flush();
        $this->addFlash('success', 'Number has been deleted.');

        return $this->redirect($this->generateUrl('oncall_admin_numbers'));
    }
}
