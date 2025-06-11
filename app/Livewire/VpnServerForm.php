namespace App\Http\Livewire;

use Livewire\Component;
use App\Models\VpnServer;

class VpnServerForm extends Component
{
    public $name, $ip, $port = 22, $loginType = 'ssh_key', $sshPassword, $sshKey, $protocol = 'openvpn';

    protected $rules = [
        'name' => 'required|string',
        'ip' => 'required|ip',
        'port' => 'required|integer',
        'loginType' => 'required|in:ssh_key,password',
        'sshPassword' => 'nullable|string|required_if:loginType,password',
        'sshKey' => 'nullable|string|required_if:loginType,ssh_key',
        'protocol' => 'required|string|in:openvpn,wireguard',
    ];

    public function submit()
    {
        $this->validate();

        // Dispatch Laravel Job or run deploy logic here
        // DeployVpnServer::dispatch(...)

        session()->flash('success', 'VPN server deployment started.');
        $this->reset();
    }

    public function render()
    {
        return view('livewire.vpn-server-form');
    }
}
