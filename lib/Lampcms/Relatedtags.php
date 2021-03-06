<?php
/**
 *
 * License, TERMS and CONDITIONS
 *
 * This software is licensed under the GNU LESSER GENERAL PUBLIC LICENSE (LGPL) version 3
 * Please read the license here : http://www.gnu.org/licenses/lgpl-3.0.txt
 *
 *  Redistribution and use in source and binary forms, with or without
 *  modification, are permitted provided that the following conditions are met:
 * 1. Redistributions of source code must retain the above copyright
 *    notice, this list of conditions and the following disclaimer.
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 * 3. The name of the author may not be used to endorse or promote products
 *    derived from this software without specific prior written permission.
 *
 * ATTRIBUTION REQUIRED
 * 4. All web pages generated by the use of this software, or at least
 *       the page that lists the recent questions (usually home page) must include
 *    a link to the http://www.lampcms.com and text of the link must indicate that
 *    the website's Questions/Answers functionality is powered by lampcms.com
 *    An example of acceptable link would be "Powered by <a href="http://www.lampcms.com">LampCMS</a>"
 *    The location of the link is not important, it can be in the footer of the page
 *    but it must not be hidden by style attributes
 *
 * THIS SOFTWARE IS PROVIDED BY THE AUTHOR "AS IS" AND ANY EXPRESS OR IMPLIED
 * WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF
 * MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
 * IN NO EVENT SHALL THE FREEBSD PROJECT OR CONTRIBUTORS BE LIABLE FOR ANY
 * DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
 * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF
 * THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This product includes GeoLite data created by MaxMind,
 *  available from http://www.maxmind.com/
 *
 *
 * @author     Dmitri Snytkine <cms@lampcms.com>
 * @copyright  2005-2012 (or current year) Dmitri Snytkine
 * @license    http://www.gnu.org/licenses/lgpl-3.0.txt GNU LESSER GENERAL PUBLIC LICENSE (LGPL) version 3
 * @link       http://www.lampcms.com   Lampcms.com project
 * @version    Release: @package_version@
 *
 *
 */


namespace Lampcms;


/**
 * Class for adding/parsing 'related tags'
 * a Related tag means the tags that often appear
 * in the same question. For example: mysql, zend, PDO
 * are normally related to 'php'
 *
 * @author Dmitri Snytkine
 *
 */
class Relatedtags extends LampcmsObject
{
    const COLLECTION = 'RELATED_TAGS';


    /**
     * Default number of tags
     * in parsed html
     *
     * @todo we will get this from oSettings
     * and use this constant as fallback value
     * like this:
     * $this->oMongo->Settings->get('max_related_tags', self::MAX_TAGS);
     *
     * @var int
     */
    const MAX_TAGS = 30;


    public function __construct(Registry $Registry)
    {
        $this->Registry = $Registry;
    }


    /**
     * This method is run when question is deleted
     * to update RELATED tags to take into account
     * all the removed tags
     *
     * @param Question $Question
     * @return \Lampcms\Relatedtags
     */
    public function removeTags(Question $Question)
    {
        $this->addTags($Question, -1);

        return $this;
    }


    /**
     * Add new batch of tags to collection
     * the array is looped and then for each
     * element (tag) the related tags values
     * are recalculated
     *
     * this involves working with existing data from MongoDB,
     * can be memory intensive operation, especially if
     * the tag already has thousand or more related tags
     * also the new html string is recreated for each tag
     *
     * It's better to run this process as post-echo process
     * after the new question has been submitted
     * but... we need a new record in QUESTIONS to be
     * created before we can redirect user to see his new question,
     * so how can we run this after? It's still possible
     * just create new record in QUESTION then runLater
     * and pass anonymous callback
     *
     * @param \Lampcms\Question $Question
     * @param int               $inc
     *
     * @internal param array $aTags
     * @return \Lampcms\Relatedtags
     */
    public function addTags(Question $Question, $inc = 1)
    {
        $aTags = $Question['a_tags'];
        /**
         * Some questions may only have one tag
         * If case of just one tag we will do nothing
         * because one element does not have any related elements
         *
         */
        if (count($aTags) < 2) {

            return $this;
        }

        foreach ($aTags as $tag) {
            $aTemp = $aTags;
            unset($aTemp[array_search($tag, $aTemp)]);

            $this->addRelated($tag, $aTemp, $inc);
        }


        return $this;
    }


    /**
     * Add/Update record in RELATED_TAGS collection
     * for this one tag
     *
     * @param string $tag
     * @param array $aTags
     * @param int $inc in case of addTag this is 1, in case of remove
     * it's -1
     *
     */
    protected function addRelated($tag, array $aTags, $inc = 1)
    {

        /**
         * Get array of 'related' for the 'tag' from Mongo,
         * For each element in $aTags:
         * if not yet in related array, add it with
         * value of 1,
         * otherwise increase counter by 1
         *
         * Increase count on related tags for each
         * new tag added, this is not hard to do at
         * the end of process by just using the new count()
         *
         * Also pre-parse the 'related tags' template
         * and save it as 'html' value
         */
        $coll = $this->Registry->Mongo->getCollection(self::COLLECTION);
        $a = $coll->findOne(array('_id' => $tag));
        if (empty($a)) {

            $aTemp = array_count_values($aTags);
            d('aTemp: ' . print_r($aTemp, 1));

        } else {
            $aTemp = $a['tags'];

            foreach ($aTags as $t) {
                if (array_key_exists($t, $aTemp)) {
                    $aTemp[$t] += $inc;
                } else {
                    $aTemp[$t] = $inc;
                }
            }
        }

        $this->parseAndSave($tag, $aTemp);
    }


    /**
     * Sort 'tags' array by count,
     * use top 30 items to create
     * html of related tags
     * then save all into Mongo Collection
     *
     * @param string $tag
     * @param array $aTemp
     * @return \Lampcms\Relatedtags
     */
    protected function parseAndSave($tag, array $aTemp)
    {
        /**
         * If any of the elements have been unset
         * the element is still there just with
         * the value of null
         * must run through array_filter to remove
         * all empty vals
         */
        $aTemp = \array_filter($aTemp);
        d('after filter: ' . \json_encode($aTemp));

        \arsort($aTemp, SORT_NUMERIC);
        /**
         * Parse related tags template
         * each item in template
         * will have a link like
         * php+mysql
         */
        $aNew = array();
        $html = '';
        $i = 1;
        foreach ($aTemp as $t => $count) {
            /*$aVars = array(
                'tag' => $t,
                'title' => $tag.' '.$t,
                'link' => \urlencode($tag).'+'.\urlencode($t),
                'i_count' => $count);*/

            /**
             * Check $count because
             * if case of removeTags the count
             * of related tags could have
             * been reduced to 0
             * which means no related tags
             */
            if ($count > 0) {
                $aNew[$t] = $count;
                $aVars = array(
                    $t,
                    $tag . ' ' . $t,
                    \urlencode($tag) . '+' . \urlencode($t),
                    $count
                );

                $html .= \tplRelatedlink::parse($aVars, false);

                $i += 1;

            }

            if ($i == self::MAX_TAGS) {
                break;
            }
        }

        /**
         * If $tag does not have related tags anymore
         * like in case of removeTags resulted of
         * removing of all related tags for certain tag,
         * we should just remove this $tag from collection
         */
        $coll = $this->Registry->Mongo->getCollection(self::COLLECTION);

        if (empty($aNew)) {
            d('removing orphan tag ' . $tag . ' from RELATED_TAGS collection');
            $coll->remove(array('_id' => $tag));
        } else {
            $aData = array('_id' => $tag, 'tags' => $aNew, 'i_count' => count($aNew), 'html' => $html);
            $coll->save($aData);
        }

        return $this;
    }


    /**
     * Get entire Mongo document for this $tag
     *
     * @param string $tag
     * @return mixed null if no record exists or array with usual
     * keys: '_id', 'tags', 'count' and 'html'
     */
    public function getRecord($tag)
    {
        $coll = $this->Registry->Mongo->getCollection(self::COLLECTION);

        return $coll->findOne(array('_id' => $tag));
    }


    /**
     * Get 'tags' array
     *
     * @param string $tag
     * @return mixed null if no record exists
     * for this $tag or array of all related tags
     * where keys are related tag names and values
     * are count of occurrences
     */
    public function getTags($tag)
    {
        $coll = $this->Registry->Mongo->getCollection(self::COLLECTION);
        $a = $coll->findOne(array('_id' => $tag), array('tags'));
        d('a: ' . print_r($a, 1));

        return $a['tags'];
    }


    /**
     * Get parsed html for related tags
     * for this $tag
     *
     * @param string $tag
     * @return string html of related tags
     * or empty string if no record for this tag exists
     */
    public function getHtml($tag)
    {
        $coll = $this->Registry->Mongo->getCollection(self::COLLECTION);
        $a = $coll->findOne(array('_id' => $tag), array('html'));
        d('a: ' . print_r($a, 1));

        return (!empty($a) && !empty($a['html'])) ? $a['html'] : '';
    }

}
