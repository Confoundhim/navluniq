import { Routes, Route } from "react-router";
import { lazy, Suspense } from "react";
import Home from "./pages/Home";
import Login from "./pages/Login";
import NotFound from "./pages/NotFound";
import AdminLogin from "./pages/admin/AdminLogin";
import AdminLayout from "./pages/admin/AdminLayout";
import Dashboard from "./pages/admin/Dashboard";

// Public pages
const LegalPage = lazy(() => import("./pages/LegalPage"));
const ShipperRegister = lazy(() => import("./pages/ShipperRegister"));
const DriverRegister = lazy(() => import("./pages/DriverRegister"));
const AboutPage = lazy(() => import("./pages/AboutPage"));
const ServicesPage = lazy(() => import("./pages/ServicesPage"));
const HowItWorksPage = lazy(() => import("./pages/HowItWorksPage"));
const PricingPage = lazy(() => import("./pages/PricingPage"));
const ContactPage = lazy(() => import("./pages/ContactPage"));

// Shipper modules (lazy loaded for code splitting)
const ShipperLayout = lazy(() => import("./pages/shipper/ShipperLayout"));
const ShipperDashboard = lazy(() => import("./pages/shipper/Dashboard"));
const NewLoad = lazy(() => import("./pages/shipper/NewLoad"));
const MyLoads = lazy(() => import("./pages/shipper/MyLoads"));
const Bids = lazy(() => import("./pages/shipper/Bids"));
const Tracking = lazy(() => import("./pages/shipper/Tracking"));
const Payments = lazy(() => import("./pages/shipper/Payments"));
const KycDocs = lazy(() => import("./pages/shipper/KycDocs"));
const Vehicles = lazy(() => import("./pages/shipper/Vehicles"));
const ShipperProfile = lazy(() => import("./pages/shipper/Profile"));
const ShipperSupport = lazy(() => import("./pages/shipper/Support"));

// Driver modules (lazy loaded for code splitting)
const DriverLayout = lazy(() => import("./pages/driver/DriverLayout"));
const DriverDashboard = lazy(() => import("./pages/driver/Dashboard"));
const AvailableLoads = lazy(() => import("./pages/driver/AvailableLoads"));
const MyBids = lazy(() => import("./pages/driver/MyBids"));
const ActiveShipments = lazy(() => import("./pages/driver/ActiveShipments"));
const Earnings = lazy(() => import("./pages/driver/Earnings"));
const DriverVehicles = lazy(() => import("./pages/driver/Vehicles"));
const DriverKyc = lazy(() => import("./pages/driver/KycDocs"));
const DriverProfile = lazy(() => import("./pages/driver/Profile"));
const DriverSupport = lazy(() => import("./pages/driver/Support"));

// Lazy load admin modules
const ShippersPage = lazy(() => import("./pages/admin/ShippersPage"));
const DriversPage = lazy(() => import("./pages/admin/DriversPage"));
const KycPage = lazy(() => import("./pages/admin/KycPage"));
const FinancePage = lazy(() => import("./pages/admin/FinancePage"));
const DisputesPage = lazy(() => import("./pages/admin/DisputesPage"));
const TicketsPage = lazy(() => import("./pages/admin/TicketsPage"));
const MarketingPage = lazy(() => import("./pages/admin/MarketingPage"));
const CmsPage = lazy(() => import("./pages/admin/CmsPage"));
const SettingsPage = lazy(() => import("./pages/admin/SettingsPage"));
const PersonnelPage = lazy(() => import("./pages/admin/PersonnelPage"));
const FirewallPage = lazy(() => import("./pages/admin/FirewallPage"));
const HealthPage = lazy(() => import("./pages/admin/HealthPage"));
const RecoveryPage = lazy(() => import("./pages/admin/RecoveryPage"));
const AiPage = lazy(() => import("./pages/admin/AiPage"));
const OperationsPage = lazy(() => import("./pages/admin/OperationsPage"));

function AdminRouteWrapper({ children }: { children: React.ReactNode }) {
  return (
    <AdminLayout>
      <Suspense fallback={
        <div className="flex items-center justify-center h-64">
          <div className="w-6 h-6 border-2 border-amber-500 border-t-transparent rounded-full animate-spin" />
        </div>
      }>
        {children}
      </Suspense>
    </AdminLayout>
  );
}

function ShipperRouteWrapper({ children }: { children: React.ReactNode }) {
  return (
    <Suspense fallback={
      <div className="min-h-screen bg-[#0F0F12] flex items-center justify-center">
        <div className="w-6 h-6 border-2 border-emerald-500 border-t-transparent rounded-full animate-spin" />
      </div>
    }>
      <ShipperLayout>{children}</ShipperLayout>
    </Suspense>
  );
}

function DriverRouteWrapper({ children }: { children: React.ReactNode }) {
  return (
    <Suspense fallback={
      <div className="min-h-screen bg-[#0F0F12] flex items-center justify-center">
        <div className="w-6 h-6 border-2 border-blue-500 border-t-transparent rounded-full animate-spin" />
      </div>
    }>
      <DriverLayout>{children}</DriverLayout>
    </Suspense>
  );
}

export default function App() {
  return (
    <Routes>
      <Route path="/" element={<Home />} />
      <Route path="/login" element={<Login />} />
      <Route path="/legal/:slug" element={<Suspense fallback={<div className="min-h-screen bg-[#0F0F12] flex items-center justify-center"><div className="w-6 h-6 border-2 border-amber-500 border-t-transparent rounded-full animate-spin" /></div>}><LegalPage /></Suspense>} />
      <Route path="/register-shipper" element={<Suspense fallback={<div className="min-h-screen bg-[#0F0F12] flex items-center justify-center"><div className="w-6 h-6 border-2 border-amber-500 border-t-transparent rounded-full animate-spin" /></div>}><ShipperRegister /></Suspense>} />
      <Route path="/register-driver" element={<Suspense fallback={<div className="min-h-screen bg-[#0F0F12] flex items-center justify-center"><div className="w-6 h-6 border-2 border-amber-500 border-t-transparent rounded-full animate-spin" /></div>}><DriverRegister /></Suspense>} />
      <Route path="/about" element={<Suspense fallback={<div className="min-h-screen bg-[#0F0F12] flex items-center justify-center"><div className="w-6 h-6 border-2 border-amber-500 border-t-transparent rounded-full animate-spin" /></div>}><AboutPage /></Suspense>} />
      <Route path="/services" element={<Suspense fallback={<div className="min-h-screen bg-[#0F0F12] flex items-center justify-center"><div className="w-6 h-6 border-2 border-amber-500 border-t-transparent rounded-full animate-spin" /></div>}><ServicesPage /></Suspense>} />
      <Route path="/how-it-works" element={<Suspense fallback={<div className="min-h-screen bg-[#0F0F12] flex items-center justify-center"><div className="w-6 h-6 border-2 border-amber-500 border-t-transparent rounded-full animate-spin" /></div>}><HowItWorksPage /></Suspense>} />
      <Route path="/pricing" element={<Suspense fallback={<div className="min-h-screen bg-[#0F0F12] flex items-center justify-center"><div className="w-6 h-6 border-2 border-amber-500 border-t-transparent rounded-full animate-spin" /></div>}><PricingPage /></Suspense>} />
      <Route path="/contact" element={<Suspense fallback={<div className="min-h-screen bg-[#0F0F12] flex items-center justify-center"><div className="w-6 h-6 border-2 border-amber-500 border-t-transparent rounded-full animate-spin" /></div>}><ContactPage /></Suspense>} />
      <Route path="/admin/login" element={<AdminLogin />} />

      {/* Admin Routes */}
      <Route path="/admin" element={<AdminRouteWrapper><Dashboard /></AdminRouteWrapper>} />
      <Route path="/admin/operations" element={<AdminRouteWrapper><OperationsPage /></AdminRouteWrapper>} />
      <Route path="/admin/ai" element={<AdminRouteWrapper><AiPage /></AdminRouteWrapper>} />
      <Route path="/admin/shippers" element={<AdminRouteWrapper><ShippersPage /></AdminRouteWrapper>} />
      <Route path="/admin/drivers" element={<AdminRouteWrapper><DriversPage /></AdminRouteWrapper>} />
      <Route path="/admin/kyc" element={<AdminRouteWrapper><KycPage /></AdminRouteWrapper>} />
      <Route path="/admin/finance" element={<AdminRouteWrapper><FinancePage /></AdminRouteWrapper>} />
      <Route path="/admin/disputes" element={<AdminRouteWrapper><DisputesPage /></AdminRouteWrapper>} />
      <Route path="/admin/tickets" element={<AdminRouteWrapper><TicketsPage /></AdminRouteWrapper>} />
      <Route path="/admin/marketing" element={<AdminRouteWrapper><MarketingPage /></AdminRouteWrapper>} />
      <Route path="/admin/cms" element={<AdminRouteWrapper><CmsPage /></AdminRouteWrapper>} />
      <Route path="/admin/settings" element={<AdminRouteWrapper><SettingsPage /></AdminRouteWrapper>} />
      <Route path="/admin/personnel" element={<AdminRouteWrapper><PersonnelPage /></AdminRouteWrapper>} />
      <Route path="/admin/firewall" element={<AdminRouteWrapper><FirewallPage /></AdminRouteWrapper>} />
      <Route path="/admin/health" element={<AdminRouteWrapper><HealthPage /></AdminRouteWrapper>} />
      <Route path="/admin/recovery" element={<AdminRouteWrapper><RecoveryPage /></AdminRouteWrapper>} />

      {/* Shipper Routes */}
      <Route path="/shipper" element={<ShipperRouteWrapper><ShipperDashboard /></ShipperRouteWrapper>} />
      <Route path="/shipper/new-load" element={<ShipperRouteWrapper><NewLoad /></ShipperRouteWrapper>} />
      <Route path="/shipper/loads" element={<ShipperRouteWrapper><MyLoads /></ShipperRouteWrapper>} />
      <Route path="/shipper/bids" element={<ShipperRouteWrapper><Bids /></ShipperRouteWrapper>} />
      <Route path="/shipper/tracking" element={<ShipperRouteWrapper><Tracking /></ShipperRouteWrapper>} />
      <Route path="/shipper/payments" element={<ShipperRouteWrapper><Payments /></ShipperRouteWrapper>} />
      <Route path="/shipper/kyc" element={<ShipperRouteWrapper><KycDocs /></ShipperRouteWrapper>} />
      <Route path="/shipper/vehicles" element={<ShipperRouteWrapper><Vehicles /></ShipperRouteWrapper>} />
      <Route path="/shipper/profile" element={<ShipperRouteWrapper><ShipperProfile /></ShipperRouteWrapper>} />
      <Route path="/shipper/support" element={<ShipperRouteWrapper><ShipperSupport /></ShipperRouteWrapper>} />

      {/* Driver Routes */}
      <Route path="/driver" element={<DriverRouteWrapper><DriverDashboard /></DriverRouteWrapper>} />
      <Route path="/driver/loads" element={<DriverRouteWrapper><AvailableLoads /></DriverRouteWrapper>} />
      <Route path="/driver/bids" element={<DriverRouteWrapper><MyBids /></DriverRouteWrapper>} />
      <Route path="/driver/shipments" element={<DriverRouteWrapper><ActiveShipments /></DriverRouteWrapper>} />
      <Route path="/driver/earnings" element={<DriverRouteWrapper><Earnings /></DriverRouteWrapper>} />
      <Route path="/driver/vehicles" element={<DriverRouteWrapper><DriverVehicles /></DriverRouteWrapper>} />
      <Route path="/driver/kyc" element={<DriverRouteWrapper><DriverKyc /></DriverRouteWrapper>} />
      <Route path="/driver/profile" element={<DriverRouteWrapper><DriverProfile /></DriverRouteWrapper>} />
      <Route path="/driver/support" element={<DriverRouteWrapper><DriverSupport /></DriverRouteWrapper>} />

      <Route path="*" element={<NotFound />} />
    </Routes>
  );
}
