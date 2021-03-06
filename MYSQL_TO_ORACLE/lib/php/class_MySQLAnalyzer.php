<?php

class MySQLAnalyzer {
    public $ddl;
    public $schema_name;
    public $table_name;
    public $table_comment;

    /**
     * @throws Exception
     */
    function __construct($DDL, $SCHEMA_NAME, $table_comment)
    {
        if(!$DDL) throw new Exception("[ERROR] DDL NOT EXIST");
        $this -> ddl = $DDL;
        if($SCHEMA_NAME != 'EXTRACTION' && $table_comment != 'EXTRACTION') {
            if(!$SCHEMA_NAME) throw new Exception("[ERROR] SCHEMA NAME NOT EXIST");
            $this -> schema_name = $SCHEMA_NAME;
            if(!$this->get_table_name()) throw new Exception("[ERROR] TABLE NAME NOT MATCHED");
            $this -> table_name = $this -> get_table_name();
            $this -> table_comment = $table_comment;
        }
    }

    function get_table_name()
    {
        $temp_ddl = $this -> ddl;
        $pattern_table = '!TABLE\s\"(.*?)\"\s!i';

        if(preg_match($pattern_table, $temp_ddl, $matches)) {
            return $matches[1];
        }
        return null;
    }

    function get_comment_list()
    {
        $temp_ddl = $this -> ddl;
        $pattern_comment = "!\"([^\"]+)\"[^\"]+COMMENT\s'([^']+)',!i";

        if(preg_match_all($pattern_comment, $temp_ddl, $matches)) {
            return $matches;
        }
        return null;
    }

    function remove_comment_in_ddl($ddl)
    {
        return preg_replace("!\sCOMMENT\s'[^']+'!i", "", $ddl);
    }

    function remove_cascade_update_in_ddl($ddl)
    {
        return preg_replace("!\sON\sUPDATE\sCASCADE!i", "", $ddl);
    }

    function make_sequence_ddl()
    {
        $seq_table_name = preg_replace("!_!i", "", $this->table_name);
        $seq_ddl_head = "CREATE SEQUENCE ";
        $seq_ddl_tail = "SEQ INCREMENT BY 1 START WITH 1;";
        return $seq_ddl_head. $this->schema_name . "." . $seq_table_name . $seq_ddl_tail;
    }

    function make_comment_ddl()
    {
        $comment_ddl_head = "COMMENT ON COLUMN ";
        $comment_ddl_tail = " IS '";
        $comment_ddl = array();
        $matched_comment_list = $this->get_comment_list();
        $result_ddl = "";
        if($this->table_comment) {
            $result_ddl .= "COMMENT ON TABLE " . $this->schema_name . "." . $this->table_name . " IS '" . $this->table_comment . "';" . chr(10);
        }
        foreach($matched_comment_list[1] as $key => $value) {
            $temp_comment_ddl_item = $comment_ddl_head . $this->table_name . "." . $matched_comment_list[1][$key] . $comment_ddl_tail . $matched_comment_list[2][$key] . "';";
            $comment_ddl[] = $temp_comment_ddl_item;
        }
        $result_ddl .= implode(chr(10), $comment_ddl);
        return $result_ddl;
    }

    function make_table_ddl()
    {
        $remove_comment_ddl = $this->remove_comment_in_ddl($this->ddl);
        $remove_cascade_update_ddl = $this->remove_cascade_update_in_ddl($remove_comment_ddl);
        $add_schema_in_references_ddl = $this->add_schema_in_references($remove_cascade_update_ddl);
        $replace_primary_key_ddl = $this->replace_primary_key($add_schema_in_references_ddl);
        $replace_type_ddl = $this->replace_type($replace_primary_key_ddl);
        return $this->replace_enum_to_check_constraints($replace_type_ddl);
    }

    function replace_type($ddl)
    {
        $replace_int_type = preg_replace("!\sint\([^\s]+\)!i", " NUMBER(11)", $ddl);
        $replace_mediumint_type = preg_replace("!\smediumint\([^\s]+\)!i", " NUMBER(11)", $replace_int_type);
        $replace_smallint_type = preg_replace("!\ssmallint\([^\s]+\)!i", " NUMBER(6)", $replace_mediumint_type);
        $replace_tinyint_type = preg_replace("!\stinyint\([^\s]+\)!i", " NUMBER(3)", $replace_smallint_type);
        $replace_datetime_type = preg_replace("!\sdatetime\s!i", " TIMESTAMP ", $replace_tinyint_type);
        $replace_text_type = preg_replace("!\stext!i", " CLOB", $replace_datetime_type);
        $replace_character_set_type = preg_replace("!\sCHARACTER\sSET\sutf8mb4!i", "", $replace_text_type);
        $replace_set_type = preg_replace("!\sset\([^)]+\)!i", " VARCHAR(30)", $replace_character_set_type);
        return $replace_set_type;
    }

    function replace_primary_key($ddl)
    {
        $constraint_primary_key_head = 'CONSTRAINT "' . $this->table_name . '_PK" PRIMARY KEY';
        return preg_replace("!PRIMARY\sKEY!is", $constraint_primary_key_head, $ddl);
    }

    function add_schema_in_references($ddl)
    {
        $replacement = 'REFERENCES ' . $this->schema_name . '.$1';
        return preg_replace('!REFERENCES\s"([^"]+)"!i', $replacement, $ddl);
    }

    function replace_enum_to_check_constraints($ddl)
    {
        $pattern_enum = '!"([^"]+)"\senum\(([^)]+)\)!i';
        $constraint_check_head = "CONSTRAINTS CHECK_";
        $constraint_check_middle_head = " CHECK (";
        $constraint_check_middle_tail = " IN (";
        $constraint_check_tail = "))";
        $constraint_check_ddl = array();
        if(preg_match_all($pattern_enum, $ddl, $matches)) {
            $count_matches = count($matches[1]);
            foreach($matches[1] as $key => $value) {
                $constraint_check_ddl_item = $constraint_check_head . $matches[1][$key] . $constraint_check_middle_head . $matches[1][$key] . $constraint_check_middle_tail . $matches[2][$key] . $constraint_check_tail;
                $constraint_check_ddl[] = $constraint_check_ddl_item;
            }
        }
        if(count($constraint_check_ddl) != 0) {
            $replace_enum_to_varchar_ddl = preg_replace('!\s(enum[^)]+\))!i', ' VARCHAR(30)', $ddl);
            $result_ddl = ',' . chr(10) . implode(',' . chr(10), $constraint_check_ddl);
            $result_ddl .= chr(10) . ');';
            return preg_replace("!\);!is", $result_ddl, $replace_enum_to_varchar_ddl);
        }
        return $ddl;
    }

    function make_synonym_ddl()
    {
        return "CREATE PUBLIC SYNONYM " . $this->table_name . " FOR " . $this->schema_name . "." . $this->table_name . ";";
    }

    function check_invalid_query_end($ddl)
    {
        $temp_ddl = $ddl;
        if(preg_match('!,\n\);!i', $ddl, $matches)) {
            $temp_ddl = preg_replace('!,\n\);!i', chr(10) . ");", $temp_ddl);
        }
        if(preg_match('!,\n,!i', $ddl, $matches)) {
            $temp_ddl = preg_replace('!,\n,!i',"," . chr(10), $temp_ddl);
        }
        return $temp_ddl;
    }

    function make_index_ddl($ddl)
    {
        if(preg_match_all('!,[\s]+KEY\s"[^"]+"\s\("([^)]+)"\)!i', $ddl, $matches)) {
            $index_ddl_head = "CREATE INDEX " . $this->schema_name . "." . $this->table_name . "_FK_IDX" . " ON " . $this->schema_name . "." . $this->table_name . "(";
            $matched_key_column = implode(", ", $matches[1]);
            $index_ddl_tail = ");";
            return $index_ddl_head . $matched_key_column . $index_ddl_tail;
        }
        return null;
    }

    function remove_key_in_ddl($ddl)
    {
        if(preg_match_all('!,[\s]+KEY\s"[^"]+"\s\("([^)]+)"\)!i', $ddl, $matches)) {
            return preg_replace('!,[\s]+KEY\s"[^"]+"\s\("([^)]+)"\)!i',"" , $ddl);
        }
        return $ddl;
    }

    function switch_null_default_location($ddl)
    {
        return preg_replace('!(NOT\sNULL)\s(DEFAULT\s[^,]+),!i', '$2 $1,', $ddl);
    }

    function extractCommentDDL()
    {
        if(preg_match_all('!(COMMENT ON COLUMN[^;]+;)!i',$this-> ddl, $matches)) {
            return $matches[1];
        }
        return null;
    }

    function extractSequenceDDL()
    {
        if(preg_match_all('!(CREATE SEQUENCE[^;]+;)!i',$this-> ddl, $matches)) {
            return $matches[1];
        }
        return null;
    }

    function extractSynonymDDL()
    {
        if(preg_match_all('!(CREATE PUBLIC SYNONYM[^;]+;)!i',$this-> ddl, $matches)) {
            return $matches[1];
        }
        return null;
    }
}

?>