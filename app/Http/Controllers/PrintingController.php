<?php

namespace App\Http\Controllers;

use App\Printing;
use App\User;
use Illuminate\Http\Request;
use Auth;
use Illuminate\Support\Facades\Gate;
use App\Rules\Numeros_USP;
use Illuminate\Support\Str;
use Carbon\Carbon;
use Uspdev\Replicado\Pessoa;
use Illuminate\Support\Facades\DB;

class PrintingController extends Controller
{
    private $noquota = ['fcs_samsung_cor_x7500lx_dcp'];
    private $proaluno = [
        'gh_samsung_pb_k7500lx_proaluno',
        'let_samsung_pb_k7500lx_proaluno',
        'fcs_samsung_pb_k7500lx_proaluno'
    ];
    
    public function __construct()
    {
        $this->middleware('auth')->except(['check','pagesToday','pendentes']);
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        # printings
        $user = \Auth::user();
        $printings = Printing::where('user', '=', $user->codpes);
        $quantidades = $this->quantidades($user->codpes, 'user');

        $printings = $printings->orderBy('jobid','DESC')->paginate(10);

        return view('printings/index', compact('printings','quantidades'));
    }

    public function admin(Request $request)
    {
        $this->authorize('admin');
        $printings =  Printing::orderBy('jobid','DESC')->paginate(10);
        $quantidades = $this->quantidades();
        return view('printings/index', compact('printings','quantidades'));
    }

    public function printer($printer) {
        $this->authorize('admin');
        $printings = Printing::where('printer', '=', $printer);
        $printings = $printings->orderBy('jobid','DESC')->paginate(10);
        $quantidades = $this->quantidades($printer, 'printer');
        return view('printings/index', compact('printings','quantidades'));
    }

    /** O método quantidades retorna quantidade impressas, ou seja, 
      * com status: Impresso.
      * Argumentos, usado na estrutura: where($type, '=', $filter)
      * type: null, user ou printer 
      * filter: o valor em si do type usado no filtro
      * Retorna um array com paǵinas impressas: hoje, mes e total 
      **/
    private function quantidades($filter=null, $type=null){
        $quantidades = [];

        if($type === null){
            $quantidades['total'] = Printing::where('status','=','Impresso')->sum(DB::raw('pages*copies'));

            $quantidades['hoje'] = Printing::where('status','=','Impresso')->whereDate('created_at', Carbon::today())->sum(DB::raw('pages*copies'));
            $quantidades['mes'] = Printing::where('status','=','Impresso')->whereMonth('created_at','=' , date('n'))->sum(DB::raw('pages*copies'));
        } else {

            $quantidades['total'] = Printing::where('status','=','Impresso')->where($type, '=', $filter)->sum(DB::raw('pages*copies'));

            $quantidades['hoje'] = Printing::where('status','=','Impresso')->where($type, '=', $filter)
                                ->whereDate('created_at', Carbon::today())
                                ->sum(DB::raw('pages*copies'));
            $quantidades['mes'] = Printing::where('status','=','Impresso')->where($type, '=', $filter)
                                ->whereMonth('created_at','=' , date('n'))
                                ->sum(DB::raw('pages*copies'));
        }
        return $quantidades;
    }

    /* Por enquanto esse método só é usado na próaluno */
    public function pagesToday($user) {   
        $proaluno_hoje = 0;
        foreach($this->proaluno as $sala) {
            $proaluno_hoje += Printing::where('status','=','Impresso')
                                ->where('user', $user)
                                ->where('printer', $sala)
                                ->whereDate('created_at', Carbon::today())
                                ->sum(DB::raw('pages*copies'));
        }
        return $proaluno_hoje;
    }

    /** Regras implementadas manualemnte.
      * Essas regras devem ir interface para ficarem mais flexíveis
      */
    public function check($user, $printer ,int $pages) {
        /* Impressoras sem controle de quota */
        if (in_array(trim($printer), $this->noquota)) {
            return "sim";
        }

        /* Qualuer usuário que começa com lab não pode imprimir */
        if (strpos($user, 'lab') !== false) {
            return 'nao';
        }

        /* Regra da sala pró-aluno: 30 por dia */
        if (in_array(trim($printer), $this->proaluno)) {
            $proaluno_hoje = 0;
            foreach($this->proaluno as $sala) {
                $proaluno_hoje += Printing::where('status','=','Impresso')
                                    ->where('user', $user)
                                    ->where('printer', $sala)
                                    ->whereDate('created_at', Carbon::today())
                                    ->sum(DB::raw('pages*copies'));
            }
            if($pages + $proaluno_hoje > 30) return 'nao';
        }

        /* Regra DF lab 103: 100 por mês */
        if ( trim($printer) == 'fcs_samsung_pb_m4080fx_dflab103') {
            $df103_mes = Printing::where('status','=','Impresso')
                           ->where('user', $user)
                           ->where('printer', 'fcs_samsung_pb_m4080fx_dflab103')
                           ->whereMonth('created_at','=' , date('n'))
                           ->sum(DB::raw('pages*copies'));
            if($pages + $df103_mes > 100) return 'nao';
        }

        /* Para todos outros casos, liberar impressão */
        return 'sim';
    }

    public function pendentes($printer = null) {
        /*$printers = Printing::select('printer')->get()->unique();*/

        if($printer != null) {
            $fila = Printing::where('status','=','Fila')->where('printer', '=', $printer)->get();
            $processando = Printing::where('status','=','Processando')->where('printer', '=', $printer)->get();
            return view('printings/pendentes', compact('fila','processando'));
        }
        $fila = Printing::where('status','=','Fila')->get();
        $processando = Printing::where('status','=','Processando')->get();
        return view('printings/pendentes', compact('fila','processando'));
    }
}
