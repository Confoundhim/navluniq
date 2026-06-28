import { useState } from "react";
import {
  Route, MapPin, Phone, MessageSquare, Package,
  Camera, CheckCircle, Upload, Clock,
  CircleDot, Flag, ChevronRight, AlertTriangle,
} from "lucide-react";

type ShipmentStatus = "accepted" | "heading_pickup" | "at_pickup" | "loading" | "in_transit" | "at_dropoff" | "unloading" | "completed";

interface Shipment {
  id: number;
  load: string;
  origin: string;
  dest: string;
  distance: string;
  weight: string;
  shipper: string;
  shipperPhone: string;
  receiver: string;
  receiverPhone: string;
  earnings: string;
  status: ShipmentStatus;
  eta: string;
  currentLocation: string;
}

const steps: { key: ShipmentStatus; label: string }[] = [
  { key: "accepted", label: "Kabul Edildi" },
  { key: "heading_pickup", label: "Yüklemeye Gidiyor" },
  { key: "at_pickup", label: "Yükleme Noktasında" },
  { key: "loading", label: "Yükleniyor" },
  { key: "in_transit", label: "Yolda" },
  { key: "at_dropoff", label: "Teslimat Noktasında" },
  { key: "unloading", label: "Boşaltılıyor" },
  { key: "completed", label: "Tamamlandı" },
];

const statusIndex = (s: ShipmentStatus) => steps.findIndex(x => x.key === s);

const demoShipments: Shipment[] = [
  {
    id: 601, load: "Ankara → İstanbul - Paletli Gıda", origin: "Ankara, Ostim OSB", dest: "İstanbul, Tuzla", distance: "450 km",
    weight: "2500 kg", shipper: "ABC Gıda A.Ş.", shipperPhone: "+90 555 111 22 33", receiver: "Mehmet Kaya", receiverPhone: "+90 555 444 55 66",
    earnings: "₺8.500", status: "in_transit", eta: "2 saat 15 dk", currentLocation: "Düzce, TEM Otoyolu"
  },
  {
    id: 602, load: "İzmir → Bursa - Konteyner", origin: "İzmir, Bornova", dest: "Bursa, Nilüfer", distance: "340 km",
    weight: "8000 kg", shipper: "XYZ Lojistik", shipperPhone: "+90 555 777 88 99", receiver: "Ali Şen",
    receiverPhone: "+90 555 000 11 22", earnings: "₺12.000", status: "loading", eta: "Hesaplanıyor", currentLocation: "İzmir, Yükleme Noktası"
  },
];

export default function ActiveShipments() {
  const [shipments, setShipments] = useState<Shipment[]>(demoShipments);
  const [expandedId, setExpandedId] = useState<number | null>(601);
  const [photoModal, setPhotoModal] = useState<{ id: number; type: "pickup" | "dropoff" } | null>(null);
  const [photoSubmitted, setPhotoSubmitted] = useState(false);

  const advanceStatus = (id: number) => {
    setShipments(shipments.map(s => {
      if (s.id !== id) return s;
      const idx = statusIndex(s.status);
      if (idx < steps.length - 1) {
        return { ...s, status: steps[idx + 1].key };
      }
      return s;
    }));
  };

  const handlePhotoSubmit = () => {
    setPhotoSubmitted(true);
    setTimeout(() => {
      setPhotoSubmitted(false);
      if (photoModal) {
        advanceStatus(photoModal.id);
        setPhotoModal(null);
      }
    }, 1500);
  };

  const nextAction = (s: Shipment) => {
    switch (s.status) {
      case "accepted": return { label: "Yüklemeye Başla", icon: Route };
      case "heading_pickup": return { label: "Yükleme Noktasına Vardım", icon: MapPin };
      case "at_pickup": return { label: "Yükleme Başladı", icon: Camera };
      case "loading": return { label: "Yükleme Tamamlandı", icon: Upload };
      case "in_transit": return { label: "Teslimat Noktasına Vardım", icon: MapPin };
      case "at_dropoff": return { label: "Boşaltma Başladı", icon: Camera };
      case "unloading": return { label: "Boşaltma Tamamlandı", icon: CheckCircle };
      default: return null;
    }
  };

  return (
    <div className="space-y-6">
      <div>
        <h1 className="text-2xl font-semibold text-white tracking-tight">Aktif Taşımalarım</h1>
        <p className="text-slate-500 text-sm mt-1">Devam eden taşımalarınızı yönetin</p>
      </div>

      {shipments.map(shipment => {
        const isExpanded = expandedId === shipment.id;
        const currentIdx = statusIndex(shipment.status);
        const progress = (currentIdx / (steps.length - 1)) * 100;
        const action = nextAction(shipment);
        const ActionIcon = action?.icon;

        return (
          <div key={shipment.id} className="bg-[#18181C] border border-[#2E2E35] rounded-xl overflow-hidden">
            {/* Header */}
            <div
              className="p-5 cursor-pointer hover:bg-white/[0.02] transition-colors"
              onClick={() => setExpandedId(isExpanded ? null : shipment.id)}
            >
              <div className="flex flex-col md:flex-row md:items-center justify-between gap-3">
                <div className="flex items-center gap-3">
                  <div className="w-10 h-10 rounded-lg bg-blue-500/10 flex items-center justify-center shrink-0">
                    <Package className="w-5 h-5 text-blue-500" />
                  </div>
                  <div>
                    <p className="text-white text-sm font-medium">{shipment.load}</p>
                    <div className="flex items-center gap-2 mt-1 text-xs text-slate-500">
                      <span>#{shipment.id}</span>
                      <span className="text-emerald-400 font-medium">{shipment.earnings}</span>
                      <span>{shipment.distance}</span>
                    </div>
                  </div>
                </div>
                <div className="flex items-center gap-3">
                  <div className="hidden sm:flex items-center gap-2 text-xs">
                    <CircleDot className="w-3 h-3 text-blue-500" />
                    <span className="text-slate-400">{steps[currentIdx].label}</span>
                  </div>
                  <ChevronRight className={`w-4 h-4 text-slate-600 transition-transform ${isExpanded ? "rotate-90" : ""}`} />
                </div>
              </div>

              {/* Mini Progress */}
              <div className="mt-3 flex items-center gap-1.5">
                <div className="flex-1 h-1.5 bg-[#232328] rounded-full overflow-hidden">
                  <div className="h-full bg-gradient-to-r from-blue-500 to-emerald-500 rounded-full transition-all" style={{ width: `${progress}%` }} />
                </div>
                <span className="text-xs text-slate-500">%{Math.round(progress)}</span>
              </div>
            </div>

            {/* Expanded Details */}
            {isExpanded && (
              <div className="border-t border-[#2E2E35] p-5 space-y-5">
                {/* Step Timeline */}
                <div className="flex items-center gap-1 overflow-x-auto pb-2">
                  {steps.map((step, idx) => {
                    const isDone = idx <= currentIdx;
                    const isCurrent = idx === currentIdx;
                    return (
                      <div key={step.key} className="flex items-center gap-1 shrink-0">
                        <div className={`flex flex-col items-center gap-1 ${idx < steps.length - 1 ? "mr-1" : ""}`}>
                          <div className={`w-7 h-7 rounded-full flex items-center justify-center text-[10px] font-bold transition-all ${
                            isCurrent ? "bg-blue-500 text-white ring-2 ring-blue-500/30" :
                            isDone ? "bg-emerald-500 text-white" : "bg-[#232328] text-slate-600"
                          }`}>
                            {isDone ? <CheckCircle className="w-3.5 h-3.5" /> : idx + 1}
                          </div>
                          <span className={`text-[9px] whitespace-nowrap ${isCurrent ? "text-blue-400" : isDone ? "text-emerald-400" : "text-slate-600"}`}>{step.label}</span>
                        </div>
                        {idx < steps.length - 1 && (
                          <div className={`w-4 h-0.5 ${idx < currentIdx ? "bg-emerald-500" : "bg-[#232328]"}`} />
                        )}
                      </div>
                    );
                  })}
                </div>

                {/* Route Info */}
                <div className="grid sm:grid-cols-2 gap-4">
                  <div className="bg-[#232328] rounded-xl p-4">
                    <div className="flex items-center gap-2 mb-2">
                      <Flag className="w-4 h-4 text-blue-500" />
                      <p className="text-white text-xs font-medium">Yükleme</p>
                    </div>
                    <p className="text-slate-300 text-sm">{shipment.origin}</p>
                    <div className="flex items-center gap-2 mt-2">
                      <p className="text-slate-500 text-xs">{shipment.shipper}</p>
                      <button className="p-1 rounded hover:bg-white/5 text-blue-400"><Phone className="w-3 h-3" /></button>
                      <button className="p-1 rounded hover:bg-white/5 text-blue-400"><MessageSquare className="w-3 h-3" /></button>
                    </div>
                  </div>
                  <div className="bg-[#232328] rounded-xl p-4">
                    <div className="flex items-center gap-2 mb-2">
                      <MapPin className="w-4 h-4 text-emerald-500" />
                      <p className="text-white text-xs font-medium">Teslimat</p>
                    </div>
                    <p className="text-slate-300 text-sm">{shipment.dest}</p>
                    <div className="flex items-center gap-2 mt-2">
                      <p className="text-slate-500 text-xs">{shipment.receiver}</p>
                      <button className="p-1 rounded hover:bg-white/5 text-emerald-400"><Phone className="w-3 h-3" /></button>
                      <button className="p-1 rounded hover:bg-white/5 text-emerald-400"><MessageSquare className="w-3 h-3" /></button>
                    </div>
                  </div>
                </div>

                {/* Current Status */}
                <div className="flex items-center gap-3 bg-blue-500/5 border border-blue-500/20 rounded-xl p-4">
                  <Clock className="w-5 h-5 text-blue-500 shrink-0" />
                  <div className="flex-1">
                    <p className="text-white text-sm">Mevcut Durum: <span className="text-blue-400 font-medium">{steps[currentIdx].label}</span></p>
                    <p className="text-slate-500 text-xs">Konum: {shipment.currentLocation} | Tahmini: {shipment.eta}</p>
                  </div>
                </div>

                {/* Action Button */}
                {action && ActionIcon && (
                  <div className="flex items-center gap-3">
                    {(shipment.status === "at_pickup" || shipment.status === "at_dropoff") ? (
                      <button
                        onClick={() => setPhotoModal({ id: shipment.id, type: shipment.status === "at_pickup" ? "pickup" : "dropoff" })}
                        className="flex-1 flex items-center justify-center gap-2 py-3 bg-blue-500 hover:bg-blue-400 text-white font-semibold rounded-xl transition-all"
                      >
                        <Camera className="w-4 h-4" /> {action.label}
                      </button>
                    ) : (
                      <button
                        onClick={() => advanceStatus(shipment.id)}
                        className="flex-1 flex items-center justify-center gap-2 py-3 bg-blue-500 hover:bg-blue-400 text-white font-semibold rounded-xl transition-all"
                      >
                        <ActionIcon className="w-4 h-4" /> {action.label}
                      </button>
                    )}
                  </div>
                )}

                {shipment.status === "completed" && (
                  <div className="flex items-center gap-2 p-4 bg-emerald-500/10 border border-emerald-500/20 rounded-xl">
                    <CheckCircle className="w-5 h-5 text-emerald-500 shrink-0" />
                    <div>
                      <p className="text-emerald-400 text-sm font-medium">Taşıma Tamamlandı</p>
                      <p className="text-slate-500 text-xs">Kazancınız 24 saat içinde cüzdanınıza aktarılacak.</p>
                    </div>
                  </div>
                )}
              </div>
            )}
          </div>
        );
      })}

      {/* Map Placeholder */}
      <div className="bg-[#18181C] border border-[#2E2E35] rounded-xl p-6 flex flex-col items-center justify-center text-center min-h-[200px]">
        <MapPin className="w-12 h-12 text-[#2E2E35] mb-3" />
        <p className="text-slate-500 text-sm">GPS Takip Sistemi yakında aktif olacak.</p>
        <p className="text-slate-600 text-xs mt-1">Gerçek zamanlı konum takibi ile taşımalarınızı izleyebileceksiniz.</p>
      </div>

      {/* Photo Modal */}
      {photoModal && (
        <div className="fixed inset-0 bg-black/70 z-50 flex items-center justify-center p-4" onClick={() => setPhotoModal(null)}>
          <div className="bg-[#18181C] border border-[#2E2E35] rounded-2xl max-w-sm w-full p-6 space-y-4" onClick={e => e.stopPropagation()}>
            {photoSubmitted ? (
              <div className="text-center py-6 space-y-3">
                <div className="w-16 h-16 rounded-full bg-emerald-500/10 flex items-center justify-center mx-auto">
                  <CheckCircle className="w-8 h-8 text-emerald-500" />
                </div>
                <h3 className="text-white font-semibold">Fotoğraf Yüklendi!</h3>
              </div>
            ) : (
              <>
                <h3 className="text-white font-semibold">
                  {photoModal.type === "pickup" ? "Yükleme Fotoğrafı" : "Boşaltma Fotoğrafı"}
                </h3>
                <div className="border-2 border-dashed border-[#2E2E35] rounded-xl p-8 text-center hover:border-blue-500/50 transition-colors cursor-pointer">
                  <Camera className="w-10 h-10 text-slate-600 mx-auto mb-2" />
                  <p className="text-slate-500 text-sm">Fotoğraf yüklemek için tıklayın</p>
                  <p className="text-slate-600 text-xs mt-1">veya sürükleyip bırakın</p>
                </div>
                <div className="flex items-center gap-2 p-3 bg-amber-500/5 border border-amber-500/20 rounded-lg">
                  <AlertTriangle className="w-4 h-4 text-amber-500 shrink-0" />
                  <p className="text-amber-400 text-xs">Fotoğraf yüklemeden bir sonraki adıma geçilemez.</p>
                </div>
                <div className="flex gap-2">
                  <button onClick={handlePhotoSubmit} className="flex-1 py-2.5 bg-blue-500 hover:bg-blue-400 text-white font-semibold rounded-lg transition-all flex items-center justify-center gap-2">
                    <Upload className="w-4 h-4" /> Yükle ve Devam Et
                  </button>
                </div>
              </>
            )}
          </div>
        </div>
      )}
    </div>
  );
}
