<?php
namespace VanXuan\PdfToText;

/*==============================================================================================================

    class PdfRC4DecryptionAlgorithm -
        A decrypter class for RC4 encoding.

  ==============================================================================================================*/

class PdfRC4DecryptionAlgorithm extends PdfDecryptionAlgorithm
{
    private static $InitialState = false;
    protected $State;


    public function __construct($encryption_data)
    {
        parent::__construct($encryption_data);

        if (self::$InitialState === false) {
            self::$InitialState = range(0, 255);
        }
    }


    public function Reset()
    {
        $this->State = self::$InitialState;
        $index1 =
        $index2 = 0;

        for ($i = 0; $i < 256; $i++) {
            $index2 = ($this->ObjectKeyBytes [$index1] + $this->State [$i] + $index2) & 0xFF;

            // Swap elements $index2 and $i from $State
            $x = $this->State [$i];
            $this->State [$i] = $this->State [$index2];
            $this->State [$index2] = $x;

            $index1 = ($index1 + 1) % $this->ObjectKeyLength;
        }
    }


    public function Decrypt($data)
    {
        $this->Reset();
        $length = strlen($data);
        $x = 0;
        $y = 0;
        $result = '';

        for ($i = 0; $i < $length; $i++) {
            $ord = ord($data [$i]);
            $x = ($x + 1) & 0xFF;
            $y = ($this->State [$x] + $y) & 0xFF;

            $tx = $this->State [$x];
            $ty = $this->State [$y];

            $this->State [$x] = $ty;
            $this->State [$y] = $tx;

            $new_ord = $ord ^ $this->State [($tx + $ty) & 0xFF];
            $result .= chr($new_ord);
        }

        return ($result);
    }
}

/*
static Guchar rc4DecryptByte(Guchar *state, Guchar *x, Guchar *y, Guchar c) {
  Guchar x1, y1, tx, ty;

  x1 = *x = (*x + 1) % 256;
  y1 = *y = (state[*x] + *y) % 256;
  tx = state[x1];
  ty = state[y1];
  state[x1] = ty;
  state[y1] = tx;
  return c ^ state[(tx + ty) % 256];
}
*/
