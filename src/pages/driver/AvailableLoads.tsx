import { useState } from "react";
import {
  Search, MapPin, Package, Scale, Calendar,
  Wallet, Star, Clock, X, ChevronRight,
  Truck, SlidersHorizontal,
} from "lucide-react";

const demoLoads = [
  { id: 501, title: "Ankara → İstanbul - Paletli Gıda", origin: "Ankara", dest: "İstanbul", distance: "450 km", weight: "2500 kg", type: "Paletli", budget: "₺9.500", date: "30.06.2026", flexible: true, urgency: "normal", shipper: "ABC Gıda", shipperRating: 4.7, bids: 3 },
  { id: 502, title: "İzmir → Bursa - Konteyner Taşıma", origin: "İzmir", dest: "Bursa", distance: "340 km", weight: "8000 kg", type: "Konteyner", budget: "₺13.500", date: "01.07.2026", flexible: false, urgency: "urgent", shipper: "XYZ Lojistik", shipperRating: 4.5, bids: 1 },
  { id: 503, title: "Mersin → Gaziantep - Tekstil", origin: "Mersin", dest: "Gaziantep", distance: "280 km", weight: "3200 kg", type: "Paletli", budget: "₺6.800", date: "29.06.2026", flexible: true, urgency: "high", shipper: "Tekstil A.Ş.", shipperRating: 4.9, bids: 5 },
  { id: 504, title: "Antalya → Ankara - Ev Eşyası", origin: "Antalya", dest: "Ankara", distance: "480 km", weight: "1500 kg", type: "Ev Eşyası", budget: "₺5.500", date: "02.07.2026", flexible: true, urgency: "normal", shipper: "Bireysel", shipperRating: 4.2, bids: 2 },
  { id: 505, title: "İstanbul → Samsun - Mobilya", origin: "İstanbul", dest: "Samsun", distance: "730 km", weight: "4500 kg", type: "Paletli", budget: "₺15.000", date: "30.06.2026", flexible: false, urgency: "urgent", shipper: "Mobilya Plus", shipperRating: 4.6, bids: 0 },
  { id: 506, title: "Bursa → İzmir - Kimyasal", origin: "Bursa", dest: "İzmir", distance: "340 km", weight: "5000 kg", type: "Tehlikeli Madde", budget: "₺11.200", date: "01.07.2026", flexible: false, urgency: "high", shipper: "ChemLogistics", shipperRating: 4.8, bids: 1 },
];

const cities = ["Tümü", "Ankara", "Antalya", "Bursa", "Gaziantep", "İstanbul", "İzmir", "Mersin", "Samsun"];
const loadTypes = ["Tümü", "Paletli", "Konteyner", "Ev Eşyası", "Tehlikeli Madde", "Soğuk Zincir"];

export default function AvailableLoads() {
  const [search, setSearch] = useState("");
  const [cityFilter, setCityFilter] = useState("Tümü");
  const [typeFilter, setTypeFilter] = useState("Tümü");
  const [sortBy, setSortBy] = useState("date");
  const [selectedLoad, setSelectedLoad] = useState<typeof demoLoads[0] | null>(null);
  const [bidAmount, setBidAmount] = useState("");
  const [bidSubmitted, setBidSubmitted] = useState(false);
  const [showFilters, setShowFilters] = useState(false);

  const filtered = demoLoads.filter(l => {
    if (search && !l.title.toLowerCase().includes(search.toLowerCase())) return false;
    if (cityFilter !== "Tümü" && l.origin !== cityFilter && l.dest !== cityFilter) return false;
    if (typeFilter !== "Tümü" && l.type !== typeFilter) return false;
    return true;
  }).sort((a, b) => {
    if (sortBy === "budget") return parseInt(b.budget.replace(/[^0-9]/g, "")) - parseInt(a.budget.replace(/[^0-9]/g, ""));
    if (sortBy === "distance") return parseInt(a.distance) - parseInt(b.distance);
    return 0;
  });

  const handleBid = (e: React.FormEvent) => {
    e.preventDefault();
    setBidSubmitted(true);
    setTimeout(() => {
      setBidSubmitted(false);
      setSelectedLoad(null);
      setBidAmount("");
    }, 2500);
  };

  const urgencyColor = (u: string) => {
    if (u === "urgent") return "bg-red-500/10 text-red-400";
    if (u === "high") return "bg-amber-500/10 text-amber-400";
    return "bg-slate-500/10 text-slate-400";
  };
  const urgencyLabel = (u: string) => {
    if (u === "urgent") return "Acil";
    if (u === "high") return "Yüksek";
    return "Normal";
  };

  return (
    <div className="space-y-4">
      <div className="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-semibold text-white tracking-tight">Mevcut Yükler</h1>
          <p className="text-slate-500 text-sm mt-1">Size uygun yük ilanlarını keşfedin</p>
        </div>
        <div className="text-right">
          <p className="text-blue-400 text-sm font-medium">{filtered.length} ilan bulundu</p>
        </div>
      </div>

      {/* Search & Filter Bar */}
      <div className="flex flex-wrap items-center gap-2">
        <div className="flex items-center gap-2 bg-[#18181C] border border-[#2E2E35] rounded-lg px-3 py-1.5 flex-1 min-w-[200px]">
          <Search className="w-4 h-4 text-slate-600" />
          <input type="text" value={search} onChange={e => setSearch(e.target.value)} placeholder="Yük ara..." className="bg-transparent text-white text-sm placeholder:text-slate-600 focus:outline-none flex-1" />
        </div>
        <button onClick={() => setShowFilters(!showFilters)} className={`flex items-center gap-1.5 px-3 py-1.5 rounded-lg text-xs font-medium transition-colors ${showFilters ? "bg-blue-500 text-white" : "bg-[#232328] text-slate-400 hover:text-white"}`}>
          <SlidersHorizontal className="w-3.5 h-3.5" /> Filtrele
        </button>
        <select value={sortBy} onChange={e => setSortBy(e.target.value)} className="h-8 px-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-xs focus:outline-none">
          <option value="date">Tarihe Göre</option>
          <option value="budget">Fiyata Göre</option>
          <option value="distance">Mesafeye Göre</option>
        </select>
      </div>

      {/* Filters */}
      {showFilters && (
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-4 space-y-3">
          <div className="flex items-center justify-between">
            <h3 className="text-white text-sm font-medium">Filtreler</h3>
            <button onClick={() => setShowFilters(false)} className="p-1 rounded hover:bg-white/5 text-slate-500"><X className="w-4 h-4" /></button>
          </div>
          <div className="grid sm:grid-cols-2 gap-3">
            <div>
              <label className="block text-xs text-slate-400 mb-1.5">Şehir</label>
              <div className="flex flex-wrap gap-1.5">
                {cities.map(c => (
                  <button key={c} onClick={() => setCityFilter(c)} className={`px-2.5 py-1 rounded-md text-xs transition-colors ${cityFilter === c ? "bg-blue-500 text-white" : "bg-[#232328] text-slate-400 hover:text-white"}`}>{c}</button>
                ))}
              </div>
            </div>
            <div>
              <label className="block text-xs text-slate-400 mb-1.5">Yük Türü</label>
              <div className="flex flex-wrap gap-1.5">
                {loadTypes.map(t => (
                  <button key={t} onClick={() => setTypeFilter(t)} className={`px-2.5 py-1 rounded-md text-xs transition-colors ${typeFilter === t ? "bg-blue-500 text-white" : "bg-[#232328] text-slate-400 hover:text-white"}`}>{t}</button>
                ))}
              </div>
            </div>
          </div>
        </div>
      )}

      {/* Load Cards */}
      <div className="space-y-3">
        {filtered.map(load => (
          <div key={load.id} className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-5 hover:border-[#3E3E45] transition-all">
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
              <div className="flex-1">
                <div className="flex items-center gap-2 mb-2 flex-wrap">
                  <p className="text-white text-sm font-medium">{load.title}</p>
                  <span className={`text-xs px-2 py-0.5 rounded-full ${urgencyColor(load.urgency)}`}>{urgencyLabel(load.urgency)}</span>
                  {load.flexible && <span className="text-xs px-2 py-0.5 rounded-full bg-blue-500/10 text-blue-400">Esnek Tarih</span>}
                </div>
                <div className="flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">
                  <span className="flex items-center gap-1"><MapPin className="w-3 h-3" />{load.distance}</span>
                  <span className="flex items-center gap-1"><Scale className="w-3 h-3" />{load.weight}</span>
                  <span className="flex items-center gap-1"><Package className="w-3 h-3" />{load.type}</span>
                  <span className="flex items-center gap-1"><Calendar className="w-3 h-3" />{load.date}</span>
                  <span className="flex items-center gap-1"><Star className="w-3 h-3 text-amber-500 fill-amber-500" />{load.shipperRating}</span>
                  <span>{load.bids} teklif</span>
                </div>
              </div>
              <div className="flex items-center gap-4">
                <div className="text-right">
                  <p className="text-emerald-400 text-lg font-bold">{load.budget}</p>
                  <p className="text-slate-600 text-xs">{load.shipper}</p>
                </div>
                <button
                  onClick={() => { setSelectedLoad(load); setBidAmount(load.budget.replace(/[^0-9]/g, "")); }}
                  className="px-4 py-2 bg-blue-500 hover:bg-blue-400 text-white text-sm font-medium rounded-lg transition-all flex items-center gap-1.5"
                >
                  Teklif Ver <ChevronRight className="w-4 h-4" />
                </button>
              </div>
            </div>
          </div>
        ))}
      </div>

      {filtered.length === 0 && (
        <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-12 text-center">
          <Search className="w-12 h-12 text-[#2E2E35] mx-auto mb-3" />
          <p className="text-slate-500 text-sm">Filtrelere uygun ilan bulunamadı.</p>
        </div>
      )}

      {/* Bid Modal */}
      {selectedLoad && (
        <div className="fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4" onClick={() => setSelectedLoad(null)}>
          <div className="bg-[#18181C] border border-[#2E2E35] rounded-2xl max-w-md w-full p-6 space-y-4" onClick={e => e.stopPropagation()}>
            {bidSubmitted ? (
              <div className="text-center py-6 space-y-3">
                <div className="w-16 h-16 rounded-full bg-emerald-500/10 flex items-center justify-center mx-auto">
                  <Star className="w-8 h-8 text-emerald-500" />
                </div>
                <h3 className="text-white font-semibold text-lg">Teklifiniz Gönderildi!</h3>
                <p className="text-slate-500 text-sm">Yük sahibi teklifinizi değerlendirecek. Sonuç bildirimle gelecek.</p>
              </div>
            ) : (
              <>
                <div className="flex items-center justify-between">
                  <h3 className="text-white font-semibold">Teklif Ver</h3>
                  <button onClick={() => setSelectedLoad(null)} className="p-1 rounded hover:bg-white/5 text-slate-500"><X className="w-5 h-5" /></button>
                </div>
                <div className="bg-[#232328] rounded-xl p-4 space-y-2">
                  <p className="text-white text-sm font-medium">{selectedLoad.title}</p>
                  <div className="flex flex-wrap gap-x-3 gap-y-1 text-xs text-slate-500">
                    <span className="flex items-center gap-1"><MapPin className="w-3 h-3" />{selectedLoad.distance}</span>
                    <span className="flex items-center gap-1"><Scale className="w-3 h-3" />{selectedLoad.weight}</span>
                    <span className="flex items-center gap-1"><Clock className="w-3 h-3" />{selectedLoad.date}</span>
                  </div>
                  <div className="flex items-center gap-1 text-xs text-slate-500">
                    <span>Bütçe: </span>
                    <span className="text-emerald-400 font-medium">{selectedLoad.budget}</span>
                    <span className="text-slate-600 mx-1">|</span>
                    <span>{selectedLoad.bids} teklif var</span>
                  </div>
                </div>
                <form onSubmit={handleBid} className="space-y-3">
                  <div>
                    <label className="block text-xs text-slate-400 mb-1.5">Teklif Tutarınız (₺)</label>
                    <div className="relative">
                      <Wallet className="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-slate-600" />
                      <input
                        type="number"
                        value={bidAmount}
                        onChange={e => setBidAmount(e.target.value)}
                        className="w-full h-10 pl-10 pr-3 bg-[#232328] border border-[#2E2E35] rounded-lg text-white text-sm focus:outline-none focus:border-blue-500"
                        placeholder="8500"
                        required
                      />
                    </div>
                    <p className="text-slate-600 text-xs mt-1">
                      {selectedLoad.bids > 0 ? `En düşük teklif verme şansı için bütçenin altında teklif verin.` : "İlk teklif sizin olabilir!"}
                    </p>
                  </div>
                  <button type="submit" className="w-full py-2.5 bg-blue-500 hover:bg-blue-400 text-white font-semibold rounded-lg transition-all flex items-center justify-center gap-2">
                    <Truck className="w-4 h-4" /> Teklifimi Gönder
                  </button>
                </form>
              </>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
