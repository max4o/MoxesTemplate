<?php
/*
 *     MoxesTemplate.php -- Template system for the Moxes Content Management System
 *
 *     Developed by Milen Hristov <milen@moxes.net>
 *     http://template.moxes.net
 *
 *     Version 1.2
 *     Revision date 03.11.2011
 *
 *     TODO: Cache, Comment, NoParse, Loop modifications
 */

class MoxesTemplate {
    private $filename;
    private $path;
    private $fh;
    private $row;
    private $openBlock = '{';
    private $closeBlock = '}';
    public $params = array();
    public $funcs = array();
    private $blocks = array();
    private $loopVars = array();
    private $print = 1;
    private $content;

    function __construct( $opt = null ) {
        if ( is_array( $opt ) ) {
            if ( ! empty( $opt['path'] ) ) {
                if ( is_dir( $opt['path'] ) ) {
                    $this->path = $opt['path'];
                } else {
                    $this->error("Invalid path {$opt['path']}");
                }
            }
            $filename   = $opt['path'] .'/'.$opt['filename'];
        } else {
            $filename = $opt;
        }
        if ( file_exists( $filename ) && is_readable( $filename ) ) {
            $this->filename = $filename;
        } else {
            $this->error("Cannot open file {$filename}");
        }
    }

    private function parseStart() {
        $this->fh = fopen( $this->filename, 'r' );
        if ( ! $this->fh ) {
            $this->error("Cannot read from file {$this->filename}");
        }
        $this->row = 1;
        $this->content = '';
        return $this->parse();
    }

    public function param( $var = '', $val = '') {
        $this->params[$var] = $val;
    }

    public function func( $var = '', $val = '') {
        $this->funcs[$var] = $val;
    }

    private function getChar() {
        $char = fgetc( $this->fh );
        if ( $char == "\n" ) {
            $this->row++;
        }
        return $char;
    }

    private function parse() {
        $return = '';
        while ( false !== ( $char = $this->getChar() ) ) {
            if ( $char == $this->openBlock ) {
                $this->addContent( $this->parseBlock() );
            } else {
                if ( $this->print ) {
                    $this->addContent( $char );
                }
            }
        }
    }

    private function parseBlock() {
        $block = '';
        while ( false !== ( $char = $this->getChar() ) ) {
            if ( $char == $this->closeBlock ) {
                return $this->block( $block );
            } else {
                $block .= $char;
            }
        }
    }

    private function parseVar( $var = '' ) {
        $el = explode( '.', $var );
        $t = count( $this->loopVars ) - 1;
        if ( ( $t >= 0 ) && isset( $this->loopVars[$t][$el[0]] ) ) {
            $p = $this->loopVars[ $t ];
        } else {
            $p = $this->params;
        }
        for ( $i = 0 ; $i < count( $el ) ; $i++ ) {
            if ( is_array( $p ) ) {
                if ( isset( $p[$el[$i]] ) ) {
                    $p = $p[$el[$i]];
                } else {
                    return null;
                }
            } elseif ( is_object( $p ) ) {
                if ( isset( $p->$el[$i] ) ) {
                    $p = $p->$el[$i];
                } else {
                    return null;
                }
            } else {
                return null;
            }
        }
        return $p;
    }

    private function block( $block = '' ) {
        if ( preg_match( '/^\s*\/\s*(\w+)\s*$/', $block, $matches) ) {
            $last = &$this->blocks[count( $this->blocks ) - 1];
            if ( isset( $last ) && ( $last['tag'] == $matches[1] ) ) {
                if ( $matches[1] == 'loop' ) {
                    array_pop( $this->loopVars );
                    if ( $last['rows'] > $last['row'] ) {
                        $last['row']++;
                        fseek( $this->fh, $last['marker'] );
                        $this->blockLoopVars( $last );
                        return;
                    }
                }
                $this->print = $last['print'];
                array_pop( $this->blocks );
            } else {
                $this->error("Cannot close an unopened block \"$matches[1]\"", $this->row);
            }
        } elseif ( preg_match( '/^\s*\$([\w|\.]+)\s*$/', $block, $matches ) ) {
            return $this->blockVar( $matches[1] );
        } elseif ( preg_match( '/^\s*loop\s+\$([\w|\.]+)\s*$/', $block, $matches ) ) {
            return $this->blockLoop( $matches[1] );
        } elseif ( preg_match( '/^\s*if\s+?(.*)\s*$/i', $block, $matches ) ) {
            return $this->blockIf( $matches[1] );
        } elseif ( preg_match( '/^\s*elseif\s+?(.*)\s*$/i', $block, $matches ) ) {
            return $this->blockElseif( $matches[1] );
        } elseif ( preg_match( '/^\s*else\s*$/i', $block, $matches ) ) {
            return $this->blockElse();
        } elseif ( preg_match( '/^\s*include\s+"(.*?)"\s*$/i', $block, $matches ) ) {
            return $this->blockInclude( $matches[1] );
        } else {
            if ( preg_match( '/^\s*(\w+)\s*("(.*?)"){0,1}\s*$/i', $block, $matches ) ) {
                if ( $this->funcs[$matches[1]] ) {
                    return $this->funcs[$matches[1]]($matches[3]);
                }
            }
            return $this->openBlock.$block.$this->closeBlock;
        }
    }

    private function blockVar( $var = '' ) {
        return $this->parseVar( $var );
    }

    private function blockInclude( $var = '' ) {
        if ( $this->path ) {
            $found = 0;
            $path = opendir( $this->path );
            while ( ( $file = readdir( $path ) ) !== false ) {
                if ( preg_match( '/^\./', $file ) ) {
                    continue;
                }
                if ( ( $var == $file ) && is_file( $this->path.'/'.$file ) ) {
                    $found = 1;
                    break;
                }
            }
            if ( ! $found ) {
                $this->error("Invalid include file {$var}");
            }
        }
        if ( $this->path ) {
            $load = new MoxesTemplate( array( 'path' => $this->path, 'filename' => $var ) );
        } else {
            $load = new MoxesTemplate( $var );
        }
        if ( $load ) {
            $load->params = $this->params;
            $load->funcs = $this->funcs;
            return $load->output();
        }
        return '';
    }

    private function blockLoop( $var = '' ) {
        array_push(
            $this->blocks, array(
                'tag'       => 'loop',
                'print'     => $this->print
            )
        );
        $last = count( $this->blocks ) - 1;
        $pVar = $this->parseVar( $var );
        if ( isset( $pVar ) && is_array( $pVar ) && ( count( $pVar ) > 0 ) ) {
            $this->blocks[$last]['var'] = $pVar;
            $this->blocks[$last]['rows'] = count( $pVar );
            $this->blocks[$last]['row'] = 1;
            $this->blocks[$last]['marker'] = ftell( $this->fh );
            $this->blockLoopVars( $this->blocks[$last] );
        } else {
            $this->print = 0;
        }
    }

    private function blockLoopVars( &$last ) {
        $val = current($last['var']);
        $key = key( $last['var'] );
        array_push( $this->loopVars,
            array(
                'key' => $key,
                'value' => $val,
                'count' => $last['row'],
                '__isLast' => $last['row'] == $last['rows'] ? 1 : 0,
                '__isFirst' => $last['row'] == 1 ? 1 : 0,
                '__isOdd'  => $last['row'] % 2 ? 1 : 0,
                '__prevKey'  => isset($last['prevKey']) ? $last['prevKey'] : '',
                '__prevValue'  => isset($last['prevVal']) ? $last['prevVal'] : '',
            )
        );
        next( $last['var'] );
        $last['prevKey'] = $key;
        $last['prevVal'] = $val;
    }

    private function blockIf( $condition ) {
        $success = $this->trueCondition( $condition );
        array_push(
            $this->blocks, array(
                'success'   => $success,
                'tag'       => 'if',
                'row'       => $this->row,
                'print'     => $this->print
                )
            );
        $this->print += $success ? 0 : -1;
    }
    private function blockElseif( $condition ) {
        $last = &$this->blocks[count( $this->blocks) -1];
        if ( $last['success'] ) {
            $this->print = $last['print'] - 1;
        } else {
            $success = $this->trueCondition( $condition );
            if ( $success ) {
                $last['success'] = true;
                $this->print++;
            }
        }
    }

    private function blockElse() {
        $t = $this->blocks[count( $this->blocks ) - 1 ];
        if ( isset( $t ) && ( $t['tag'] == 'if' ) ) {
            $this->print += $t['success'] ? -1 : 1;
        } else {
            $this->error("Unopened block \"else\"", $t['row']);
        }
    }

    private function trueCondition( $condition = '' ) {
        if ( preg_match( '/^\s*\$([\w|\.]+)\s*$/' , $condition, $matches ) ) {
            return $this->parseVar( $matches[1] ) ? true : false;
        } elseif ( preg_match( '/^\s*\!\s*\$([\w|\.]+)\s*$/' , $condition, $matches ) ) {
            return $this->parseVar( $matches[1] ) ? false : true;
        } elseif ( preg_match( '/^\s*isset\(\s*\$([\w|\.]+)\s*\)\s*$/i' , $condition, $matches ) ) {
            return $this->parseVar( $matches[1] ) !== null ? true : false;
        } elseif ( preg_match( '/^\s*\!\s*isset\(\s*\$([\w|\.]+)\s*\)\s*$/i' , $condition, $matches ) ) {
            return $this->parseVar( $matches[1] ) === null ? true : false;
        } elseif ( preg_match( '/^\s*(\$([\w|\.]+)|"(.*?)")\s*([\=|\>|\<|\!]\={0,1}%{0,1})\s*(\$([\w|\.]+)|"(.*?)")\s*$/' , $condition, $matches ) ) {
            $t1 = $matches[2] ? $this->parseVar( $matches[2]) : $matches[3];
            $t2 = $matches[6] ? $this->parseVar( $matches[6]) : $matches[7];
            switch ( $matches[4] ) {
                case "==":
                    return $t1 == $t2 ? true : false; break;
                case "!=":
                    return $t1 != $t2 ? true : false; break;
                case ">=":
                    return $t1 >= $t2 ? true : false; break;
                case "<=":
                    return $t1 <= $t2 ? true : false; break;
                case ">":
                    return $t1 > $t2 ? true : false; break;
                case "<":
                    return $t1 < $t2 ? true : false; break;
                case "%":
                    return $t1 % $t2 ? true : false; break;
                case "!%":
                    return $t1 % $t2 ? false : true; break;
                default:
                    $this->error("Invalid statement \"{$matches[4]}\"", $this->row); break;
            }
        } else {
            $this->error("Invalid statement", $this->row );
        }
    }

    private function addContent( $t ) {
        if ( $this->print > 0 ) {
            $this->content .= $t;
        }
    }

    public function output() {
        $this->parseStart();
        $countBlocks = count( $this->blocks );
        if ( $countBlocks > 0 ) {
            $this->error("There is unclosed block \"{$this->blocks[$countBlocks-1]['tag']}\"", $this->blocks[$countBlocks-1]['row']);
        }
        return $this->content;
    }

    private function error( $error = '', $row = '' ) {
        die("MoxesTemplate: {$error}".( $row ? " ({$this->filename}: {$this->row})":""));
    }

    public function filename() {
        return $this->filename;
    }
}

?>
