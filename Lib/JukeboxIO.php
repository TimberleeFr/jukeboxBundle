<?php
/**
 * Created by PhpStorm.
 * User: fabrice
 * Date: 10/12/2016
 * Time: 08:32
 */

namespace Fabgg\JukeboxBundle\Lib;

use Doctrine\ORM\EntityManager;
use Symfony\Component\DependencyInjection\Container;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\Session\Session;
use Tbl\JukeboxBundle\Exception\JKException;
use Tbl\JukeboxBundle\Model\JKFile;


class JukeboxIO
{
    protected $em;
    protected $session;

    protected $JKManager;

    public function __construct(EntityManager $entityManager, Session $session)
    {
        $this->em = $entityManager;
        $this->session = $session;
    }

    public function setManager(\Tbl\JukeboxBundle\Lib\JukeboxManager $jukeboxManager){
        $this->JKManager= $jukeboxManager;
    }

    public function put(File $file, JKFile $JKFile){
        $JKFile->setFilePath($this->JKManager->getNewRandPath());
        $JKFile->setFileExtension($file->getExtension());
        $JKFile->setFileMine($file->getMimeType());
        $JKFile->setFileName($file->getFilename());
        $JKFile->setFileSize($file->getSize());
        $utils = new JukeboxUtils();
        $JKFile->setFileSlug($utils->slugify($file->getFilename()));
        $this->em->persist($JKFile);
        $this->em->flush();
        if($JKFile->getId()){
            $file->move(
                $this->JKManager->getAbsolutePath($JKFile), //Destination
                $JKFile->getFileName()
            );
            unset($file);
        } else {
            throw new JKException('Unable to save JKFile in db',104);
        }
        return $JKFile;
    }


    /**
     * @param JKFile $JKFile
     * @return $link
     */
    public function getLink(JKFile $JKFile){
        if($JKFile->getPublic()){
           $link =  'public/'.$JKFile->getId().'/';
        }
        else {
            $token = 'jk_'.bin2hex(openssl_random_pseudo_bytes(8));
            $this->session->set($token,$JKFile->getId());
            $link = 'private/'.$token.'/';
        }
        return $link.$JKFile->getFileSlug();
    }

    /**
     * @param $link
     * @return $JKFileId
     */
    public function parseLink($link){
        $linkElements = explode('/',$link);
        if(count($linkElements)!=3) throw new JKException('parseLink > argument $link is not a jukebox link',105);
        if ($linkElements[0] == 'public') return $linkElements[1];
        elseif ($linkElements[0] == 'private'){
            $JKFileId = $this->session->get($linkElements[1]);
            if($JKFileId) return $JKFileId;
            else throw new JKException('parseLink > the token in the link are not longer set',106);
        } else throw new JKException('parseLink > unable to identify public or private link',107);

    }

    /**
     * @param JKFile $JKFile
     * @return BinaryFileResponse
     */
    public function getSteam(JKFile $JKFile){
        $fullPath = $this->JKManager->getAbsolutePath($JKFile).$JKFile->getFileName();
        $response = new BinaryFileResponse($fullPath);
        $response->headers->set('Content-Type', $JKFile->getFileMine());
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $JKFile->getFileName()
        );
        return $response;
    }

    /**
     * @param JKFile $JKFile
     * @param bool|false $remove if true the file will hard deleted, else the file will be already in the application just deleted on entity set true
     */
    public function delete(JKFile $JKFile, $remove = false){
        if($remove){
            $this->JKManager->remove($JKFile);
            $this->em->remove($JKFile);
        } else{
            $JKFile->setDeleted(true);
            $this->em->merge($JKFile);
        }
        $this->em->flush($JKFile);
    }

}