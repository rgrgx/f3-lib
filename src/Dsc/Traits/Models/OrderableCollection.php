<?php
namespace Dsc\Traits\Models;

trait OrderableCollection 
{
    public $ordering;
    
    /**
     * Move the model up, meaning decrease ordering value and increase all others where ordering = $this->ordering-1 and _id != $this->id 
     * 
     * @return \Dsc\Traits\Models\OrderableCollection
     */
    public function moveUp($filters=array())
    {
        if (!empty($this->__type)) {
            $filters = $filters + array(
                'type' => $this->__type
            );
        }
        
        $this->ordering = $this->ordering - 1;
        
        $this->collection()->update(
            array( 'ordering' => $this->ordering ) + $filters,
            array( '$inc' => array( 'ordering' => 1 ) ),
            array( 'multiple' => false )
        );
        
        return $this->save();
    }
    
    /**
     * Move the model down, meaning increase ordering value and decrease all others where ordering = $this->ordering+1 and _id != $this->id
     *
     * @return \Dsc\Traits\Models\OrderableCollection
     */
    public function moveDown($filters=array())
    {
        if (!empty($this->__type)) {
            $filters = $filters + array(
                'type' => $this->__type
            );
        }
                
        $this->ordering = $this->ordering + 1;
        
        $this->collection()->update(
            array( 'ordering' => $this->ordering ) + $filters,
            array( '$inc' => array( 'ordering' => -1 ) ),
            array( 'multiple' => false )
        );
        
        return $this->save();
    }
    
    /**
     * Add this to your model's afterSave method
     * to compress ordering values after each save [optional]
     */
    public function compressOrdering($filters=array())
    {
        if (!empty($this->__type)) {
            $filters = $filters + array(
                'type' => $this->__type
            );
        }
                
        $this->__cursor = $this->collection()->find($filters);
        $this->__cursor->sort(array('ordering' => 1));
                
        $count=1;
        $start = microtime(true);
        
        foreach ($this->__cursor as $doc)
        {
            set_time_limit(0);
        
            $this->collection()->update(
                array( '_id' => $doc['_id'] ),
                array( '$set' => array( 'ordering' => $count ) ),
                array( 'multiple' => false )
            );
            
            $count++;
        }
        
        $time_taken = microtime(true) - $start;
        
        return $this;
    }
    
    /**
     * Find the next ordering number
     * 
     * @return number
     */
    public function nextOrdering($filters=array())
    {
        if (!empty($this->__type)) {
            $filters = $filters + array(
                'type' => $this->__type
            );
        }
                
        $result = $this->collection()->aggregate(array(
            array( '$match' => $filters ),
            array( '$group' => array( '_id' => 0, 'maxOrdering' => array( '$max' => '$ordering' ) ) )
        ) );
        
        $return = 999999999;
        if (!empty($result['ok']) && isset($result['result'][0]['maxOrdering'])) {
            $return = (int) $result['result'][0]['maxOrdering'] + 1;
        }
        
        return $return;
    }
    
    protected function orderingBeforeSave($filters=array()) 
    {
        if (empty($this->ordering)) {
            $this->ordering = $this->nextOrdering($filters);
        }
        $this->ordering = (int) $this->ordering;
        
        return $this;
    }
}
